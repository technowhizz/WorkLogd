<?php

declare(strict_types=1);

namespace App\Service;

use App\Models\JiraWorklogCreation;
use App\Models\Organization;
use Illuminate\Support\Carbon;

/**
 * What a given organization's plan lets it do.
 *
 * Separate from {@see BillingContract}, which answers whether an organization is paying at all.
 * This answers what follows from that, so a controller never has to reason about plans directly.
 *
 * Every answer here is the generous one while `billing.enforce` is off, so a self-hosted instance
 * is unaffected until an operator decides otherwise.
 */
class EntitlementService
{
    public function __construct(
        private readonly BillingContract $billing,
    ) {}

    /**
     * Whether the organization is on a paid plan or inside a trial.
     */
    public function isPaid(Organization $organization): bool
    {
        if (! $this->isEnforced()) {
            return true;
        }

        return $this->billing->hasSubscription($organization) || $this->billing->hasTrial($organization);
    }

    public function allowsGoogleCalendar(Organization $organization): bool
    {
        if ($this->isPaid($organization)) {
            return true;
        }

        return (bool) config('billing.free.google_calendar', false);
    }

    /**
     * How many worklogs the organization may still create in Jira this week.
     *
     * Null means no limit. Zero means the allowance is spent and creating more has to wait for
     * the reset - existing worklogs can still be corrected or removed.
     */
    public function jiraWorklogsRemainingThisWeek(Organization $organization): ?int
    {
        $limit = $this->jiraWorklogsPerWeek($organization);

        if ($limit === null) {
            return null;
        }

        return max(0, $limit - $this->jiraWorklogsUsedThisWeek($organization));
    }

    public function jiraWorklogsPerWeek(Organization $organization): ?int
    {
        if ($this->isPaid($organization)) {
            return null;
        }

        $limit = config('billing.free.jira_worklogs_per_week');

        return $limit === null ? null : (int) $limit;
    }

    /**
     * Worklogs this organization has created in Jira since the week began.
     *
     * Counted from the append-only creation ledger rather than from the live jira_worklogs rows.
     * Those get deleted when a worklog is removed from Jira, which made the allowance refundable:
     * sync five, delete them, sync five more, forever. A creation is a fact about the past and
     * nothing a customer does afterwards unmakes it.
     */
    public function jiraWorklogsUsedThisWeek(Organization $organization): int
    {
        return JiraWorklogCreation::query()
            ->whereBelongsTo($organization, 'organization')
            ->where('created_at', '>=', $this->weekStartsAt())
            ->count();
    }

    /**
     * When the current allowance period began.
     *
     * A calendar week rather than a rolling window: "resets Monday" is something a person can
     * hold in their head, where "seven days from whenever you last synced" is not.
     */
    public function weekStartsAt(): Carbon
    {
        return Carbon::now('UTC')->startOfWeek(Carbon::MONDAY);
    }

    public function weekResetsAt(): Carbon
    {
        return $this->weekStartsAt()->addWeek();
    }

    private function isEnforced(): bool
    {
        return (bool) config('billing.enforce', false);
    }
}
