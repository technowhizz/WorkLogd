<?php

declare(strict_types=1);

namespace Tests\Unit\Service\Jira;

use App\Enums\TimeEntryType;
use App\Models\JiraConnection;
use App\Models\JiraWorklog;
use App\Models\JiraWorklogCreation;
use App\Models\Member;
use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\TimeEntry;
use App\Models\User;
use App\Service\DeletionService;
use App\Service\EntitlementService;
use App\Service\Jira\JiraSyncPlanDto;
use App\Service\Jira\JiraSyncService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCaseWithDatabase;

/**
 * The free tier's weekly cap on new Jira worklogs.
 */
#[CoversClass(JiraSyncService::class)]
class JiraSyncQuotaTest extends TestCaseWithDatabase
{
    private const string SITE_URL = 'https://acme.atlassian.net';

    private const string WORKLOG_URL = 'https://api.atlassian.com/ex/jira/*/rest/api/3/issue/*';

    // Entitlements have to come from the real subscription records, not the suite-wide mock.
    protected bool $mockBillingContract = false;

    private User $user;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('billing.enforce', true);
        Config::set('billing.free.jira_worklogs_per_week', 2);

        $this->user = User::factory()->create(['timezone' => 'UTC']);
        $this->organization = Organization::factory()->withOwner($this->user)->create([
            'jira_site_url' => self::SITE_URL,
        ]);
        Member::factory()->forUser($this->user)->forOrganization($this->organization)->create();
        JiraConnection::factory()->forUser($this->user)->forOrganization($this->organization)->create();
    }

    private function plan(): JiraSyncPlanDto
    {
        return $this->service()->plan($this->user, $this->organization, '2026-08-05', '2026-08-05');
    }

    private function service(): JiraSyncService
    {
        return app(JiraSyncService::class);
    }

    private function timeEntry(string $description, string $start, string $end): TimeEntry
    {
        return TimeEntry::factory()
            ->forUser($this->user)
            ->forOrganization($this->organization)
            ->create([
                'description' => $description,
                'start' => $start,
                'end' => $end,
                'type' => TimeEntryType::Work,
            ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function syncThreeEntries(): array
    {
        $this->timeEntry('PROJ-1 first', '2026-08-05T09:00:00', '2026-08-05T10:00:00');
        $this->timeEntry('PROJ-2 second', '2026-08-05T10:00:00', '2026-08-05T11:00:00');
        $this->timeEntry('PROJ-3 third', '2026-08-05T11:00:00', '2026-08-05T12:00:00');

        Http::fake([self::WORKLOG_URL => Http::response(['id' => '10001'], 200)]);

        $plan = $this->service()->plan($this->user, $this->organization, '2026-08-05', '2026-08-05');

        return $this->service()->execute($this->user, $this->organization, $plan);
    }

    public function test_a_free_organization_stops_at_its_weekly_allowance(): void
    {
        // Act
        $results = $this->syncThreeEntries();

        // Assert
        $statuses = array_column($results, 'status');
        $this->assertSame(['done', 'done', 'skipped'], $statuses);
        $this->assertSame(2, JiraWorklog::query()->whereBelongsTo($this->organization, 'organization')->count());
    }

    public function test_the_skipped_item_says_why(): void
    {
        // Act
        $results = $this->syncThreeEntries();

        // Assert
        $skipped = array_values(array_filter($results, fn (array $r): bool => $r['status'] === 'skipped'));
        $this->assertCount(1, $skipped);
        $this->assertStringContainsString('allowance', (string) $skipped[0]['error']);
    }

    public function test_a_paid_organization_syncs_all_of_them(): void
    {
        // Arrange
        OrganizationSubscription::factory()->forOrganization($this->organization)->create();

        // Act
        $results = $this->syncThreeEntries();

        // Assert
        $this->assertSame(['done', 'done', 'done'], array_column($results, 'status'));
        $this->assertSame(3, JiraWorklog::query()->whereBelongsTo($this->organization, 'organization')->count());
    }

    public function test_nothing_is_capped_while_enforcement_is_off(): void
    {
        // Arrange
        Config::set('billing.enforce', false);

        // Act
        $results = $this->syncThreeEntries();

        // Assert
        $this->assertSame(['done', 'done', 'done'], array_column($results, 'status'));
    }

    public function test_worklogs_already_created_this_week_count_against_the_allowance(): void
    {
        // Arrange
        // Seeded on the ledger rather than as live worklogs: live rows are no longer what the
        // allowance is counted from, precisely so that removing them cannot refund it.
        JiraWorklogCreation::factory()->count(2)->create([
            'organization_id' => $this->organization->getKey(),
            'user_id' => $this->user->getKey(),
        ]);

        // Act
        $results = $this->syncThreeEntries();

        // Assert
        $this->assertSame(['skipped', 'skipped', 'skipped'], array_column($results, 'status'));
    }

    public function test_deleting_synced_worklogs_does_not_hand_the_allowance_back(): void
    {
        // Arrange: spend the whole allowance, then remove everything that was synced
        $entries = [
            $this->timeEntry('PROJ-1 first', '2026-08-05T09:00:00', '2026-08-05T10:00:00'),
            $this->timeEntry('PROJ-2 second', '2026-08-05T10:00:00', '2026-08-05T11:00:00'),
        ];
        Http::fake([self::WORKLOG_URL => Http::response(['id' => '10001'], 200)]);
        $this->service()->execute($this->user, $this->organization, $this->plan());
        $this->assertSame(2, JiraWorklogCreation::query()->count());

        foreach ($entries as $entry) {
            $entry->delete();
        }

        // Act: syncing now removes them from Jira, which used to delete the rows the allowance
        // was counted from - the "sync five, delete, sync five more" loop.
        $this->service()->execute($this->user, $this->organization, $this->plan());
        $this->timeEntry('PROJ-3 third', '2026-08-05T11:00:00', '2026-08-05T12:00:00');
        $results = $this->service()->execute($this->user, $this->organization, $this->plan());

        // Assert
        $this->assertSame(['skipped'], array_column($results, 'status'));
        $this->assertSame(0, JiraWorklog::query()->count());
        // The ledger still remembers the two that were created.
        $this->assertSame(2, JiraWorklogCreation::query()->count());
    }

    public function test_rewording_an_entry_onto_a_new_ticket_spends_a_fresh_slot(): void
    {
        // Arrange: Jira cannot move a worklog between issues, so a ticket change is a delete and
        // a create - and the create is new work being logged, so it should cost a slot.
        $entry = $this->timeEntry('PROJ-1 fix login', '2026-08-05T09:00:00', '2026-08-05T10:00:00');
        Http::fake([self::WORKLOG_URL => Http::response(['id' => '10001'], 200)]);
        $this->service()->execute($this->user, $this->organization, $this->plan());

        // Act
        $entry->description = 'PROJ-9 fix login';
        $entry->save();
        $this->service()->execute($this->user, $this->organization, $this->plan());

        // Assert
        $this->assertSame(2, JiraWorklogCreation::query()->count());
    }

    public function test_a_failed_jira_request_does_not_cost_a_slot(): void
    {
        // Arrange
        $this->timeEntry('PROJ-1 first', '2026-08-05T09:00:00', '2026-08-05T10:00:00');
        Http::fake([self::WORKLOG_URL => Http::response(['errorMessages' => ['nope']], 500)]);

        // Act
        $results = $this->service()->execute($this->user, $this->organization, $this->plan());

        // Assert
        $this->assertSame(['failed'], array_column($results, 'status'));
        // Nothing reached Jira, so nothing was spent.
        $this->assertSame(0, JiraWorklogCreation::query()->count());
        $this->assertSame(2, app(EntitlementService::class)->jiraWorklogsRemainingThisWeek($this->organization));
    }

    public function test_last_weeks_creations_do_not_count_against_this_week(): void
    {
        // Arrange
        JiraWorklogCreation::factory()->count(5)->create([
            'organization_id' => $this->organization->getKey(),
            'user_id' => $this->user->getKey(),
            'created_at' => Carbon::now('UTC')->startOfWeek(Carbon::MONDAY)->subSecond(),
        ]);

        // Act
        $remaining = app(EntitlementService::class)->jiraWorklogsRemainingThisWeek($this->organization);

        // Assert
        $this->assertSame(2, $remaining);
    }

    public function test_another_organizations_creations_do_not_count(): void
    {
        // Arrange
        JiraWorklogCreation::factory()->count(5)->create([
            'organization_id' => Organization::factory()->create()->getKey(),
            'user_id' => $this->user->getKey(),
        ]);

        // Act & Assert
        $this->assertSame(2, app(EntitlementService::class)->jiraWorklogsRemainingThisWeek($this->organization));
    }

    public function test_deleting_the_organization_clears_its_ledger(): void
    {
        // Arrange
        JiraWorklogCreation::factory()->count(3)->create([
            'organization_id' => $this->organization->getKey(),
            'user_id' => $this->user->getKey(),
        ]);

        // Act
        app(DeletionService::class)->deleteOrganization($this->organization);

        // Assert
        // Append-only for the organization's own lifetime, not beyond it - the foreign key would
        // otherwise refuse the deletion outright.
        $this->assertSame(0, JiraWorklogCreation::query()->count());
    }

    public function test_disconnecting_and_reconnecting_jira_does_not_reset_the_allowance(): void
    {
        // Arrange: spend the allowance
        $this->timeEntry('PROJ-1 first', '2026-08-05T09:00:00', '2026-08-05T10:00:00');
        $this->timeEntry('PROJ-2 second', '2026-08-05T10:00:00', '2026-08-05T11:00:00');
        Http::fake([self::WORKLOG_URL => Http::response(['id' => '10001'], 200)]);
        $this->service()->execute($this->user, $this->organization, $this->plan());
        $this->assertSame(0, app(EntitlementService::class)->jiraWorklogsRemainingThisWeek($this->organization));

        // Act: throw the credentials away and put fresh ones back, as the disconnect endpoint and
        // then the connect form would. A different Atlassian account would look the same here.
        JiraConnection::query()->where('user_id', '=', $this->user->getKey())->delete();
        JiraConnection::factory()->forUser($this->user)->forOrganization($this->organization)->create();

        $this->timeEntry('PROJ-3 third', '2026-08-05T11:00:00', '2026-08-05T12:00:00');
        $results = $this->service()->execute($this->user, $this->organization, $this->plan());

        // Assert
        // The allowance belongs to the organization, not to the credentials, so replacing the
        // credentials changes nothing about it.
        $this->assertSame(['skipped'], array_column($results, 'status'));
        $this->assertSame(0, app(EntitlementService::class)->jiraWorklogsRemainingThisWeek($this->organization));
    }

    public function test_leaving_and_rejoining_an_organization_does_not_reset_the_allowance(): void
    {
        // Arrange
        JiraWorklogCreation::factory()->count(2)->create([
            'organization_id' => $this->organization->getKey(),
            'user_id' => $this->user->getKey(),
        ]);
        $member = Member::query()
            ->where('organization_id', '=', $this->organization->getKey())
            ->where('user_id', '=', $this->user->getKey())
            ->firstOrFail();

        // Act
        $member->delete();
        Member::factory()->forUser($this->user)->forOrganization($this->organization)->create();

        // Assert
        // Membership is not what the ledger is keyed on, so churning it buys nothing.
        $this->assertSame(0, app(EntitlementService::class)->jiraWorklogsRemainingThisWeek($this->organization));
    }

    public function test_correcting_a_worklog_does_not_spend_the_allowance(): void
    {
        // Arrange: one worklog already in Jira, and the allowance fully spent by others
        $timeEntry = $this->timeEntry('PROJ-1 fix login', '2026-08-05T09:00:00', '2026-08-05T10:00:00');
        Http::fake([self::WORKLOG_URL => Http::response(['id' => '10001'], 200)]);
        $this->service()->execute(
            $this->user,
            $this->organization,
            $this->service()->plan($this->user, $this->organization, '2026-08-05', '2026-08-05')
        );
        JiraWorklog::factory()->count(5)->create([
            'organization_id' => $this->organization->getKey(),
            'user_id' => $this->user->getKey(),
        ]);

        // Act: reword it, which is an update rather than a create
        $timeEntry->description = 'PROJ-1 fix the login';
        $timeEntry->save();
        Http::fake([self::WORKLOG_URL => Http::response([], 200)]);
        $results = $this->service()->execute(
            $this->user,
            $this->organization,
            $this->service()->plan($this->user, $this->organization, '2026-08-05', '2026-08-05')
        );

        // Assert
        $this->assertSame(['done'], array_column($results, 'status'));
    }
}
