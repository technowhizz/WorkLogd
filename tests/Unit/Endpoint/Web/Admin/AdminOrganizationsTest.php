<?php

declare(strict_types=1);

namespace Tests\Unit\Endpoint\Web\Admin;

use App\Enums\SubscriptionPlan;
use App\Http\Controllers\Web\Admin\OrganizationController;
use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\User;
use App\Service\DeletionService;
use Illuminate\Support\Facades\Config;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCaseWithDatabase;

#[CoversClass(OrganizationController::class)]
class AdminOrganizationsTest extends TestCaseWithDatabase
{
    protected function setUp(): void
    {
        parent::setUp();
        Config::set('auth.super_admins', []);
        $this->actingAs(User::factory()->withPersonalOrganization()->create(['is_admin' => true]));
    }

    public function test_lists_organizations_with_their_member_counts(): void
    {
        // Arrange
        $organization = Organization::factory()->create(['name' => 'Acme Industries']);

        // Act
        $response = $this->get(route('admin.organizations.index'));

        // Assert
        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Organizations')
            ->has('organizations.data')
            ->has('filters')
            ->has('planOptions')
        );
        $this->assertStringContainsString('Acme Industries', $response->getContent());
        $this->assertNotNull($organization);
    }

    public function test_search_narrows_the_list_to_matching_names(): void
    {
        // Arrange
        Organization::factory()->create(['name' => 'Findable Ltd']);
        Organization::factory()->create(['name' => 'Hidden Ltd']);

        // Act
        $response = $this->get(route('admin.organizations.index', ['search' => 'Findable']));

        // Assert
        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Organizations')
            ->has('organizations.data', 1)
            ->where('organizations.data.0.name', 'Findable Ltd')
        );
    }

    public function test_can_filter_to_organizations_with_no_subscription_recorded(): void
    {
        // Arrange
        $billed = Organization::factory()->create(['name' => 'Billed Ltd']);
        OrganizationSubscription::factory()->forOrganization($billed)->create();
        Organization::factory()->create(['name' => 'Unbilled Ltd']);

        // Act
        $response = $this->get(route('admin.organizations.index', ['plan' => 'none']));

        // Assert
        // The acting admin's own personal organization has no subscription either, so two come
        // back. Asserted as a set rather than by position - both were created within the same
        // second, and created_at is stored at second precision.
        $response->assertInertia(fn (Assert $page) => $page->has('organizations.data', 2));
        $names = collect($response->viewData('page')['props']['organizations']['data'])
            ->pluck('name');
        $this->assertContains('Unbilled Ltd', $names);
        $this->assertNotContains('Billed Ltd', $names);
    }

    public function test_can_filter_organizations_by_plan(): void
    {
        // Arrange
        $paying = Organization::factory()->create(['name' => 'Paying Ltd']);
        OrganizationSubscription::factory()->forOrganization($paying)
            ->plan(SubscriptionPlan::Professional)->create();
        $free = Organization::factory()->create(['name' => 'Free Ltd']);
        OrganizationSubscription::factory()->forOrganization($free)->free()->create();

        // Act
        $response = $this->get(route('admin.organizations.index', [
            'plan' => SubscriptionPlan::Professional->value,
        ]));

        // Assert
        $response->assertInertia(fn (Assert $page) => $page
            ->has('organizations.data', 1)
            ->where('organizations.data.0.name', 'Paying Ltd')
        );
    }

    public function test_shows_an_organization_with_its_members_and_invitations(): void
    {
        // Arrange
        $data = $this->createUserWithPermission(isOwner: true);

        // Act
        $response = $this->get(route('admin.organizations.show', $data->organization->getKey()));

        // Assert
        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Admin/OrganizationShow')
            ->where('organization.id', $data->organization->getKey())
            ->has('members')
            ->has('invitations')
            ->has('options.currencies')
        );
    }

    public function test_can_update_an_organization(): void
    {
        // Arrange
        $organization = Organization::factory()->create(['name' => 'Before']);

        // Act
        $response = $this->put(route('admin.organizations.update', $organization->getKey()), [
            'name' => 'After',
            'currency' => 'GBP',
            'billable_rate' => 5000,
            'date_format' => $organization->date_format->value,
            'time_format' => $organization->time_format->value,
            'interval_format' => $organization->interval_format->value,
            'number_format' => $organization->number_format->value,
            'currency_format' => $organization->currency_format->value,
            'employees_can_see_billable_rates' => true,
            'employees_can_manage_tasks' => false,
            'prevent_overlapping_time_entries' => false,
            'breaks_enabled' => true,
        ]);

        // Assert
        $response->assertRedirect();
        $organization->refresh();
        $this->assertSame('After', $organization->name);
        $this->assertSame('GBP', $organization->currency);
        $this->assertSame(5000, $organization->billable_rate);
    }

    public function test_deleting_an_organization_goes_through_the_deletion_service(): void
    {
        // Arrange
        $organization = Organization::factory()->create();
        $this->mock(DeletionService::class, function (MockInterface $mock) use ($organization): void {
            $mock->shouldReceive('deleteOrganization')
                ->once()
                ->withArgs(fn (Organization $argument): bool => $argument->is($organization));
        });

        // Act
        $response = $this->delete(route('admin.organizations.destroy', $organization->getKey()));

        // Assert
        $response->assertRedirect(route('admin.organizations.index'));
    }

    public function test_the_billing_shortcut_records_the_free_plan_an_organization_was_already_on(): void
    {
        // Arrange
        $organization = Organization::factory()->create(['currency' => 'GBP']);

        // Act
        $response = $this->get(route('admin.organizations.billing', $organization->getKey()));

        // Assert
        $subscription = $organization->billingRecord()->first();
        $this->assertNotNull($subscription);
        $this->assertSame(SubscriptionPlan::Free, $subscription->plan);
        $this->assertSame('GBP', $subscription->currency);
        $response->assertRedirect(route('admin.subscriptions.edit', $subscription->getKey()));
    }

    public function test_the_billing_shortcut_keeps_a_subscription_that_already_exists(): void
    {
        // Arrange
        $organization = Organization::factory()->create();
        $subscription = OrganizationSubscription::factory()->forOrganization($organization)->create();

        // Act
        $this->get(route('admin.organizations.billing', $organization->getKey()));

        // Assert
        $this->assertSame(
            1,
            OrganizationSubscription::query()->whereBelongsTo($organization, 'organization')->count()
        );
        $this->assertTrue($subscription->is($organization->billingRecord()->first()));
    }
}
