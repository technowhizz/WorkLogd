<?php

declare(strict_types=1);

namespace Tests\Unit\Model;

use App\Enums\BillingInterval;
use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionStatus;
use App\Models\Organization;
use App\Models\OrganizationSubscription;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(OrganizationSubscription::class)]
class OrganizationSubscriptionModelTest extends ModelTestAbstract
{
    public function test_belongs_to_an_organization(): void
    {
        // Arrange
        $organization = Organization::factory()->create();
        $subscription = OrganizationSubscription::factory()->forOrganization($organization)->create();

        // Act
        $related = $subscription->organization()->first();

        // Assert
        $this->assertTrue($organization->is($related));
        $this->assertTrue($subscription->is($organization->billingRecord()->first()));
    }

    public function test_a_new_subscription_records_the_free_plan_it_was_already_on(): void
    {
        // Arrange
        $organization = Organization::factory()->create();
        $subscription = new OrganizationSubscription;
        $subscription->organization()->associate($organization);

        // Act
        $subscription->save();

        // Assert
        $this->assertSame(SubscriptionPlan::Free, $subscription->plan);
        $this->assertSame(SubscriptionStatus::Active, $subscription->status);
        $this->assertFalse($subscription->isActive());
    }

    public function test_a_paid_subscription_that_has_not_started_yet_is_not_active(): void
    {
        // Arrange
        $subscription = OrganizationSubscription::factory()->create([
            'starts_at' => Carbon::now()->addWeek(),
        ]);

        // Act & Assert
        $this->assertFalse($subscription->isActive());
    }

    public function test_a_past_due_subscription_stays_active(): void
    {
        // Arrange
        $subscription = OrganizationSubscription::factory()->status(SubscriptionStatus::PastDue)->create();

        // Act & Assert
        $this->assertTrue($subscription->isActive());
    }

    public function test_monthly_price_spreads_a_yearly_subscription_across_the_year(): void
    {
        // Arrange
        $subscription = OrganizationSubscription::factory()->create([
            'price' => 12000,
            'billing_interval' => BillingInterval::Yearly,
        ]);

        // Act & Assert
        $this->assertSame(1000, $subscription->monthlyPrice());
    }

    public function test_monthly_price_of_a_monthly_subscription_is_its_price(): void
    {
        // Arrange
        $subscription = OrganizationSubscription::factory()->create([
            'price' => 2500,
            'billing_interval' => BillingInterval::Monthly,
        ]);

        // Act & Assert
        $this->assertSame(2500, $subscription->monthlyPrice());
    }

    public function test_monthly_price_of_a_lapsed_subscription_is_nothing(): void
    {
        // Arrange
        $subscription = OrganizationSubscription::factory()->status(SubscriptionStatus::Expired)->create();

        // Act & Assert
        $this->assertSame(0, $subscription->monthlyPrice());
    }

    public function test_monthly_price_is_unknown_without_a_price(): void
    {
        // Arrange
        $subscription = OrganizationSubscription::factory()->free()->create();

        // Act & Assert
        $this->assertNull($subscription->monthlyPrice());
    }

    public function test_a_trial_that_has_run_out_is_no_longer_a_trial(): void
    {
        // Arrange
        $running = OrganizationSubscription::factory()->onTrial()->create();
        $expired = OrganizationSubscription::factory()->expiredTrial()->create();

        // Act & Assert
        $this->assertTrue($running->isOnTrial());
        $this->assertFalse($expired->isOnTrial());
    }
}
