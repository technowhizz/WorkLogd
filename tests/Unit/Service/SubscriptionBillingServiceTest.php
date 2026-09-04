<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use App\Enums\Role;
use App\Enums\SubscriptionStatus;
use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\User;
use App\Service\SubscriptionBillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

#[CoversClass(SubscriptionBillingService::class)]
class SubscriptionBillingServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): SubscriptionBillingService
    {
        return new SubscriptionBillingService;
    }

    private function addRealMembers(Organization $organization, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $organization->users()->attach(User::factory()->create(), ['role' => Role::Employee->value]);
        }
    }

    public function test_organization_without_a_subscription_keeps_full_access_while_enforcement_is_off(): void
    {
        // Arrange
        Config::set('billing.enforce', false);
        $organization = Organization::factory()->create();
        $this->addRealMembers($organization, 3);

        // Act & Assert
        $this->assertTrue($this->service()->hasSubscription($organization));
        $this->assertFalse($this->service()->hasTrial($organization));
        $this->assertNull($this->service()->getTrialUntil($organization));
        $this->assertFalse($this->service()->isBlocked($organization));
    }

    public function test_expired_paid_subscription_does_not_block_while_enforcement_is_off(): void
    {
        // Arrange
        Config::set('billing.enforce', false);
        $organization = Organization::factory()->create();
        $this->addRealMembers($organization, 3);
        OrganizationSubscription::factory()
            ->forOrganization($organization)
            ->status(SubscriptionStatus::Expired)
            ->create();

        // Act & Assert
        $this->assertTrue($this->service()->hasSubscription($organization));
        $this->assertFalse($this->service()->isBlocked($organization));
    }

    public function test_organization_without_a_subscription_has_no_subscription_when_enforced(): void
    {
        // Arrange
        Config::set('billing.enforce', true);
        $organization = Organization::factory()->create();

        // Act & Assert
        $this->assertFalse($this->service()->hasSubscription($organization));
        $this->assertFalse($this->service()->hasTrial($organization));
    }

    public function test_active_paid_subscription_has_subscription_when_enforced(): void
    {
        // Arrange
        Config::set('billing.enforce', true);
        $organization = Organization::factory()->create();
        OrganizationSubscription::factory()->forOrganization($organization)->create();

        // Act & Assert
        $this->assertTrue($this->service()->hasSubscription($organization));
        $this->assertFalse($this->service()->isBlocked($organization));
    }

    public function test_free_plan_does_not_count_as_a_subscription_when_enforced(): void
    {
        // Arrange
        Config::set('billing.enforce', true);
        $organization = Organization::factory()->create();
        OrganizationSubscription::factory()->forOrganization($organization)->free()->create();

        // Act & Assert
        $this->assertFalse($this->service()->hasSubscription($organization));
    }

    public function test_running_trial_counts_as_a_trial_when_enforced(): void
    {
        // Arrange
        Config::set('billing.enforce', true);
        $organization = Organization::factory()->create();
        $trialEnd = Carbon::now()->addDays(5);
        OrganizationSubscription::factory()->forOrganization($organization)->onTrial($trialEnd)->create();

        // Act & Assert
        $this->assertTrue($this->service()->hasTrial($organization));
        $this->assertSame(
            $trialEnd->toIso8601ZuluString(),
            $this->service()->getTrialUntil($organization)?->toIso8601ZuluString()
        );
    }

    public function test_expired_trial_is_not_a_trial_when_enforced(): void
    {
        // Arrange
        Config::set('billing.enforce', true);
        $organization = Organization::factory()->create();
        OrganizationSubscription::factory()->forOrganization($organization)->expiredTrial()->create();

        // Act & Assert
        $this->assertFalse($this->service()->hasTrial($organization));
        $this->assertNull($this->service()->getTrialUntil($organization));
    }

    public function test_cancelled_subscription_still_counts_until_the_paid_period_is_over(): void
    {
        // Arrange
        Config::set('billing.enforce', true);
        $organization = Organization::factory()->create();
        OrganizationSubscription::factory()
            ->forOrganization($organization)
            ->cancelledAt(Carbon::now()->addWeek())
            ->create();

        // Act & Assert
        $this->assertTrue($this->service()->hasSubscription($organization));
    }

    public function test_cancelled_subscription_stops_counting_once_the_paid_period_is_over(): void
    {
        // Arrange
        Config::set('billing.enforce', true);
        $organization = Organization::factory()->create();
        OrganizationSubscription::factory()
            ->forOrganization($organization)
            ->cancelledAt(Carbon::now()->subDay())
            ->create();

        // Act & Assert
        $this->assertFalse($this->service()->hasSubscription($organization));
    }

    public function test_single_member_organization_is_not_blocked_without_a_subscription_when_enforced(): void
    {
        // Arrange
        Config::set('billing.enforce', true);
        $organization = Organization::factory()->create();
        $this->addRealMembers($organization, 1);

        // Act & Assert
        $this->assertFalse($this->service()->isBlocked($organization));
    }

    public function test_multi_member_organization_is_blocked_without_a_subscription_when_enforced(): void
    {
        // Arrange
        Config::set('billing.enforce', true);
        $organization = Organization::factory()->create();
        $this->addRealMembers($organization, 2);

        // Act & Assert
        $this->assertTrue($this->service()->isBlocked($organization));
    }

    public function test_placeholder_members_do_not_count_towards_blocking(): void
    {
        // Arrange
        Config::set('billing.enforce', true);
        $organization = Organization::factory()->create();
        $this->addRealMembers($organization, 1);
        $organization->users()->attach(
            User::factory()->create(['is_placeholder' => true]),
            ['role' => Role::Employee->value]
        );

        // Act & Assert
        $this->assertFalse($this->service()->isBlocked($organization));
    }

    public function test_trial_keeps_a_multi_member_organization_unblocked_when_enforced(): void
    {
        // Arrange
        Config::set('billing.enforce', true);
        $organization = Organization::factory()->create();
        $this->addRealMembers($organization, 3);
        OrganizationSubscription::factory()->forOrganization($organization)->onTrial()->create();

        // Act & Assert
        $this->assertFalse($this->service()->isBlocked($organization));
    }

    public function test_counts_members_over_the_seats_that_were_paid_for(): void
    {
        // Arrange
        $organization = Organization::factory()->create();
        $this->addRealMembers($organization, 5);
        OrganizationSubscription::factory()->forOrganization($organization)->create([
            'seats' => 3,
        ]);

        // Act & Assert
        $this->assertSame(2, $this->service()->countMembersOverSeats($organization));
    }

    public function test_counts_nobody_over_seats_when_the_subscription_has_no_seat_cap(): void
    {
        // Arrange
        $organization = Organization::factory()->create();
        $this->addRealMembers($organization, 5);
        OrganizationSubscription::factory()->forOrganization($organization)->create([
            'seats' => null,
        ]);

        // Act & Assert
        $this->assertSame(0, $this->service()->countMembersOverSeats($organization));
    }

    public function test_counts_nobody_over_seats_when_there_is_no_subscription(): void
    {
        // Arrange
        $organization = Organization::factory()->create();
        $this->addRealMembers($organization, 5);

        // Act & Assert
        $this->assertSame(0, $this->service()->countMembersOverSeats($organization));
    }
}
