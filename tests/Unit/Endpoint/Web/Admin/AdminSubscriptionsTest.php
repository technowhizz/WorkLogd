<?php

declare(strict_types=1);

namespace Tests\Unit\Endpoint\Web\Admin;

use App\Enums\BillingInterval;
use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionStatus;
use App\Http\Controllers\Web\Admin\SubscriptionController;
use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCaseWithDatabase;

#[CoversClass(SubscriptionController::class)]
class AdminSubscriptionsTest extends TestCaseWithDatabase
{
    protected function setUp(): void
    {
        parent::setUp();
        Config::set('auth.super_admins', []);
        Config::set('billing.currency', 'EUR');
        $this->actingAs(User::factory()->withPersonalOrganization()->create(['is_admin' => true]));
    }

    public function test_lists_subscriptions_with_the_billing_stats(): void
    {
        // Arrange
        OrganizationSubscription::factory()->createMany(3);

        // Act
        $response = $this->get(route('admin.subscriptions.index'));

        // Assert
        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Subscriptions')
            ->has('subscriptions.data', 3)
            ->has('stats.monthly_revenue')
            ->has('stats.paying')
            ->where('stats.enforced', false)
        );
    }

    public function test_the_stats_total_monthly_revenue_across_paying_organizations(): void
    {
        // Arrange
        OrganizationSubscription::factory()->create([
            'price' => 2500, 'currency' => 'EUR', 'billing_interval' => BillingInterval::Monthly,
        ]);
        OrganizationSubscription::factory()->create([
            'price' => 12000, 'currency' => 'EUR', 'billing_interval' => BillingInterval::Yearly,
        ]);

        // Act
        $response = $this->get(route('admin.subscriptions.index'));

        // Assert
        $response->assertInertia(fn (Assert $page) => $page
            ->where('stats.monthly_revenue', 3500)
            ->where('stats.paying', 2)
        );
    }

    public function test_can_filter_to_trials_ending_within_a_week(): void
    {
        // Arrange
        OrganizationSubscription::factory()->onTrial(Carbon::now()->addDays(2))->create();
        OrganizationSubscription::factory()->onTrial(Carbon::now()->addMonth())->create();

        // Act
        $response = $this->get(route('admin.subscriptions.index', ['trials' => 'ending']));

        // Assert
        $response->assertInertia(fn (Assert $page) => $page->has('subscriptions.data', 1));
    }

    public function test_can_filter_subscriptions_by_plan(): void
    {
        // Arrange
        OrganizationSubscription::factory()->plan(SubscriptionPlan::Professional)->create();
        OrganizationSubscription::factory()->free()->create();

        // Act
        $response = $this->get(route('admin.subscriptions.index', [
            'plan' => SubscriptionPlan::Professional->value,
        ]));

        // Assert
        $response->assertInertia(fn (Assert $page) => $page
            ->has('subscriptions.data', 1)
            ->where('subscriptions.data.0.plan', 'professional')
        );
    }

    public function test_can_create_a_subscription_for_an_organization(): void
    {
        // Arrange
        $organization = Organization::factory()->create();

        // Act
        $response = $this->post(route('admin.subscriptions.store'), [
            'organization_id' => $organization->getKey(),
            'plan' => SubscriptionPlan::Professional->value,
            'status' => SubscriptionStatus::Active->value,
            'seats' => 5,
            'price' => 2500,
            'currency' => 'EUR',
            'billing_interval' => BillingInterval::Monthly->value,
            'trial_ends_at' => null,
            'starts_at' => null,
            'ends_at' => null,
            'external_reference' => 'INV-1',
            'note' => null,
        ]);

        // Assert
        $response->assertRedirect(route('admin.subscriptions.index'));
        $subscription = $organization->billingRecord()->first();
        $this->assertNotNull($subscription);
        $this->assertSame(SubscriptionPlan::Professional, $subscription->plan);
        $this->assertSame(5, $subscription->seats);
        $this->assertSame(2500, $subscription->price);
        $this->assertSame('INV-1', $subscription->external_reference);
    }

    public function test_refuses_a_second_subscription_for_the_same_organization(): void
    {
        // Arrange
        $organization = Organization::factory()->create();
        OrganizationSubscription::factory()->forOrganization($organization)->create();

        // Act
        $response = $this->post(route('admin.subscriptions.store'), [
            'organization_id' => $organization->getKey(),
            'plan' => SubscriptionPlan::Professional->value,
            'status' => SubscriptionStatus::Active->value,
            'seats' => null, 'price' => null, 'currency' => null, 'billing_interval' => null,
            'trial_ends_at' => null, 'starts_at' => null, 'ends_at' => null,
            'external_reference' => null, 'note' => null,
        ]);

        // Assert
        $response->assertSessionHas('bannerStyle', 'danger');
        $this->assertSame(
            1,
            OrganizationSubscription::query()->whereBelongsTo($organization, 'organization')->count()
        );
    }

    public function test_can_update_a_subscription(): void
    {
        // Arrange
        $subscription = OrganizationSubscription::factory()->free()->create();

        // Act
        $response = $this->put(route('admin.subscriptions.update', $subscription->getKey()), [
            'plan' => SubscriptionPlan::Enterprise->value,
            'status' => SubscriptionStatus::Active->value,
            'seats' => 25, 'price' => 90000, 'currency' => 'GBP',
            'billing_interval' => BillingInterval::Yearly->value,
            'trial_ends_at' => null, 'starts_at' => null, 'ends_at' => null,
            'external_reference' => null, 'note' => 'Agreed on a call',
        ]);

        // Assert
        $response->assertRedirect();
        $subscription->refresh();
        $this->assertSame(SubscriptionPlan::Enterprise, $subscription->plan);
        $this->assertSame(25, $subscription->seats);
        $this->assertSame('Agreed on a call', $subscription->note);
    }

    public function test_can_start_a_trial(): void
    {
        // Arrange
        Config::set('billing.trial_days', 21);
        $subscription = OrganizationSubscription::factory()->free()->create();

        // Act
        $response = $this->post(route('admin.subscriptions.start-trial', $subscription->getKey()));

        // Assert
        $response->assertRedirect();
        $subscription->refresh();
        $this->assertSame(SubscriptionStatus::Trialing, $subscription->status);
        $this->assertNotNull($subscription->trial_ends_at);
        $this->assertSame(21, (int) round(Carbon::now()->diffInDays($subscription->trial_ends_at)));
    }

    public function test_can_delete_a_subscription(): void
    {
        // Arrange
        $subscription = OrganizationSubscription::factory()->create();

        // Act
        $response = $this->delete(route('admin.subscriptions.destroy', $subscription->getKey()));

        // Assert
        $response->assertRedirect(route('admin.subscriptions.index'));
        $this->assertDatabaseMissing('organization_subscriptions', ['id' => $subscription->getKey()]);
    }

    public function test_the_edit_screen_loads_the_member_count_it_shows(): void
    {
        // Arrange
        $subscription = OrganizationSubscription::factory()->create();

        // Act
        $response = $this->get(route('admin.subscriptions.edit', $subscription->getKey()));

        // Assert
        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Admin/SubscriptionEdit')
            ->has('subscription.members_count')
            ->has('organizations', 1)
        );
    }
}
