<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use App\Enums\SubscriptionStatus;
use App\Models\JiraWorklog;
use App\Models\JiraWorklogCreation;
use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Service\EntitlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

#[CoversClass(EntitlementService::class)]
class EntitlementServiceTest extends TestCase
{
    use RefreshDatabase;

    // The base TestCase mocks BillingContract to "no subscription" for the whole suite. These
    // tests are about how the real subscription records drive entitlements, so they need the
    // real thing behind it.
    protected bool $mockBillingContract = false;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('billing.enforce', true);
        Config::set('billing.free.jira_worklogs_per_week', 5);
        Config::set('billing.free.google_calendar', false);
    }

    private function service(): EntitlementService
    {
        return app(EntitlementService::class);
    }

    private function paidOrganization(): Organization
    {
        $organization = Organization::factory()->create();
        OrganizationSubscription::factory()->forOrganization($organization)->create();

        return $organization;
    }

    public function test_nothing_is_limited_while_enforcement_is_off(): void
    {
        // Arrange
        Config::set('billing.enforce', false);
        $organization = Organization::factory()->create();

        // Act & Assert
        $this->assertTrue($this->service()->allowsGoogleCalendar($organization));
        $this->assertNull($this->service()->jiraWorklogsPerWeek($organization));
        $this->assertNull($this->service()->jiraWorklogsRemainingThisWeek($organization));
    }

    public function test_a_free_organization_does_not_get_google_calendar(): void
    {
        // Arrange
        $organization = Organization::factory()->create();

        // Act & Assert
        $this->assertFalse($this->service()->allowsGoogleCalendar($organization));
    }

    public function test_a_paid_organization_gets_google_calendar(): void
    {
        // Act & Assert
        $this->assertTrue($this->service()->allowsGoogleCalendar($this->paidOrganization()));
    }

    public function test_an_organization_on_trial_gets_google_calendar(): void
    {
        // Arrange
        $organization = Organization::factory()->create();
        OrganizationSubscription::factory()->forOrganization($organization)->onTrial()->create();

        // Act & Assert
        $this->assertTrue($this->service()->allowsGoogleCalendar($organization));
    }

    public function test_a_lapsed_organization_loses_google_calendar(): void
    {
        // Arrange
        $organization = Organization::factory()->create();
        OrganizationSubscription::factory()->forOrganization($organization)
            ->status(SubscriptionStatus::Expired)->create();

        // Act & Assert
        $this->assertFalse($this->service()->allowsGoogleCalendar($organization));
    }

    public function test_a_paid_organization_has_no_jira_worklog_limit(): void
    {
        // Act & Assert
        $this->assertNull($this->service()->jiraWorklogsPerWeek($this->paidOrganization()));
        $this->assertNull($this->service()->jiraWorklogsRemainingThisWeek($this->paidOrganization()));
    }

    public function test_a_free_organization_starts_the_week_with_the_full_allowance(): void
    {
        // Arrange
        $organization = Organization::factory()->create();

        // Act & Assert
        $this->assertSame(5, $this->service()->jiraWorklogsPerWeek($organization));
        $this->assertSame(5, $this->service()->jiraWorklogsRemainingThisWeek($organization));
    }

    public function test_creations_this_week_spend_the_allowance(): void
    {
        // Arrange
        $organization = Organization::factory()->create();
        JiraWorklogCreation::factory()->count(2)->create(['organization_id' => $organization->getKey()]);

        // Act & Assert
        $this->assertSame(2, $this->service()->jiraWorklogsUsedThisWeek($organization));
        $this->assertSame(3, $this->service()->jiraWorklogsRemainingThisWeek($organization));
    }

    public function test_the_allowance_never_reads_as_negative(): void
    {
        // Arrange
        $organization = Organization::factory()->create();
        JiraWorklogCreation::factory()->count(9)->create(['organization_id' => $organization->getKey()]);

        // Act & Assert
        $this->assertSame(0, $this->service()->jiraWorklogsRemainingThisWeek($organization));
    }

    public function test_creations_from_last_week_do_not_count(): void
    {
        // Arrange
        $organization = Organization::factory()->create();
        JiraWorklogCreation::factory()->count(3)->create([
            'organization_id' => $organization->getKey(),
            'created_at' => Carbon::now('UTC')->startOfWeek(Carbon::MONDAY)->subDay(),
        ]);

        // Act & Assert
        $this->assertSame(0, $this->service()->jiraWorklogsUsedThisWeek($organization));
        $this->assertSame(5, $this->service()->jiraWorklogsRemainingThisWeek($organization));
    }

    public function test_another_organizations_creations_do_not_count(): void
    {
        // Arrange
        $organization = Organization::factory()->create();
        JiraWorklogCreation::factory()->count(4)->create([
            'organization_id' => Organization::factory()->create()->getKey(),
        ]);

        // Act & Assert
        $this->assertSame(5, $this->service()->jiraWorklogsRemainingThisWeek($organization));
    }

    public function test_removing_the_live_worklogs_does_not_refund_the_allowance(): void
    {
        // Arrange
        $organization = Organization::factory()->create();
        JiraWorklogCreation::factory()->count(5)->create([
            'organization_id' => $organization->getKey(),
        ]);

        // Act
        // Nothing here deletes ledger rows, which is the point - the old implementation counted
        // live jira_worklogs, so deleting the worklogs handed the whole allowance straight back.
        JiraWorklog::query()->delete();

        // Assert
        $this->assertSame(0, $this->service()->jiraWorklogsRemainingThisWeek($organization));
    }

    public function test_the_allowance_resets_at_the_start_of_next_week(): void
    {
        // Act
        $resets = $this->service()->weekResetsAt();

        // Assert
        $this->assertSame(Carbon::MONDAY, $resets->dayOfWeek);
        $this->assertTrue($resets->isFuture());
    }
}
