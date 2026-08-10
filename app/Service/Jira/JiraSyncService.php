<?php

declare(strict_types=1);

namespace App\Service\Jira;

use App\Exceptions\Api\JiraAuthenticationFailedApiException;
use App\Exceptions\Api\JiraNotConfiguredApiException;
use App\Exceptions\Api\JiraNotConnectedApiException;
use App\Exceptions\Api\JiraRequestFailedApiException;
use App\Models\JiraConnection;
use App\Models\JiraWorklog;
use App\Models\Organization;
use App\Models\TimeEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Reconciles a user's solidtime time entries with the Jira worklogs solidtime created for them.
 *
 * Reconciliation rather than a "have I sent this before" ledger is what fixes the original
 * script's worst behaviour: it keyed off individual entry ids, so adding a fourth entry to an
 * already-logged group made the whole group look done and that time never reached Jira.
 */
class JiraSyncService
{
    public function __construct(
        private readonly JiraConfig $config,
        private readonly JiraClientContract $client,
        private readonly JiraWorklogGrouper $grouper,
    ) {}

    public function connectionFor(User $user, Organization $organization): ?JiraConnection
    {
        return JiraConnection::query()
            ->where('user_id', '=', $user->getKey())
            ->where('organization_id', '=', $organization->getKey())
            ->first();
    }

    public function requireConnection(User $user, Organization $organization): JiraConnection
    {
        if (! $this->config->isConfigured($organization)) {
            throw new JiraNotConfiguredApiException;
        }

        $connection = $this->connectionFor($user, $organization);
        if ($connection === null) {
            throw new JiraNotConnectedApiException;
        }

        return $connection;
    }

    /**
     * Works out what the sync would do, without sending anything. Drives the preview dialog.
     *
     * @param  string  $startDate  Local date (Y-m-d), inclusive
     * @param  string  $endDate  Local date (Y-m-d), inclusive
     */
    public function plan(User $user, Organization $organization, string $startDate, string $endDate): JiraSyncPlanDto
    {
        $syncFromDate = $this->connectionFor($user, $organization)?->sync_from_date?->format('Y-m-d');
        $timeEntries = $this->timeEntriesForRange($user, $organization, $startDate, $endDate);
        $grouping = $this->grouper->group($timeEntries, $user->timezone, $this->config->projectKeys($organization), $syncFromDate);
        // Clamped to the cutoff as well, so a worklog from before it is never reconciled away
        $worklogs = $this->worklogsForRange($user, $organization, max($startDate, $syncFromDate ?? $startDate), $endDate);

        /**
         * Keyed by the position each item takes in the preview, because whether a group creates a
         * worklog or takes an existing one over is only known after every group has been seen.
         * Sorted back into a list at the end.
         *
         * @var array<int, JiraSyncItemDto>
         */
        $items = [];
        $matchedHashes = [];
        /**
         * Groups with no worklog under their own hash, against the position they will occupy.
         *
         * @var array<int, JiraWorklogGroupDto>
         */
        $unmatchedGroups = [];
        $position = 0;

        foreach ($grouping->groups as $group) {
            $existing = $worklogs->get($group->groupHash);

            if ($existing === null) {
                $unmatchedGroups[$position++] = $group;

                continue;
            }

            $matchedHashes[] = $group->groupHash;

            $items[$position++] = new JiraSyncItemDto(
                action: $this->isUpToDate($existing, $group) ? JiraSyncAction::Unchanged : JiraSyncAction::Update,
                issueKey: $group->issueKey,
                workDate: $group->workDate,
                comment: $group->comment,
                groupHash: $group->groupHash,
                durationSeconds: $group->durationSeconds,
                previousDurationSeconds: $existing->duration_seconds,
                startedAt: $group->startedAt,
                jiraWorklogId: $existing->jira_worklog_id,
                timeEntryIds: $group->timeEntryIds,
            );
        }

        /*
         * Editing a description makes a new group, because the comment is part of the hash - but on
         * the same ticket and the same day it is still the same worklog, so take the existing one
         * over and update it rather than deleting it and making another. Jira treats those as
         * different things: a recreated worklog loses its id, its place in the issue's history and
         * anything anyone attached to it.
         *
         * Only leftovers are paired, so a group that matched its own hash is never stolen from, and
         * a change that moves time to a *different* ticket still deletes and creates - that really
         * is different work.
         */
        $spareWorklogs = [];
        foreach ($worklogs as $hash => $worklog) {
            if (in_array($hash, $matchedHashes, true)) {
                continue;
            }

            $spareWorklogs[$worklog->issue_key."\0".$worklog->work_date->format('Y-m-d')][] = $worklog;
        }
        // Same data has to produce the same plan, whatever order the rows came back in
        foreach ($spareWorklogs as &$candidates) {
            usort($candidates, static fn (JiraWorklog $a, JiraWorklog $b): int => [$a->comment ?? '', $a->jira_worklog_id] <=> [$b->comment ?? '', $b->jira_worklog_id]);
        }
        unset($candidates);

        foreach ($unmatchedGroups as $index => $group) {
            $key = $group->issueKey."\0".$group->workDate;
            $candidate = ($spareWorklogs[$key] ?? []) === []
                ? null
                : array_shift($spareWorklogs[$key]);

            if ($candidate === null) {
                $items[$index] = new JiraSyncItemDto(
                    action: JiraSyncAction::Create,
                    issueKey: $group->issueKey,
                    workDate: $group->workDate,
                    comment: $group->comment,
                    groupHash: $group->groupHash,
                    durationSeconds: $group->durationSeconds,
                    previousDurationSeconds: null,
                    startedAt: $group->startedAt,
                    jiraWorklogId: null,
                    timeEntryIds: $group->timeEntryIds,
                );

                continue;
            }

            $matchedHashes[] = $candidate->group_hash;
            $items[$index] = new JiraSyncItemDto(
                action: JiraSyncAction::Update,
                issueKey: $group->issueKey,
                workDate: $group->workDate,
                comment: $group->comment,
                groupHash: $group->groupHash,
                durationSeconds: $group->durationSeconds,
                previousDurationSeconds: $candidate->duration_seconds,
                startedAt: $group->startedAt,
                jiraWorklogId: $candidate->jira_worklog_id,
                timeEntryIds: $group->timeEntryIds,
                previousGroupHash: $candidate->group_hash,
            );
        }

        // Anything solidtime logged in this range that no longer corresponds to a group: its
        // entries were deleted, or edited onto a different ticket.
        foreach ($worklogs as $hash => $worklog) {
            if (in_array($hash, $matchedHashes, true)) {
                continue;
            }

            $items[$position++] = new JiraSyncItemDto(
                action: JiraSyncAction::Delete,
                issueKey: $worklog->issue_key,
                workDate: $worklog->work_date->format('Y-m-d'),
                comment: $worklog->comment,
                groupHash: $worklog->group_hash,
                durationSeconds: $worklog->duration_seconds,
                previousDurationSeconds: $worklog->duration_seconds,
                startedAt: null,
                jiraWorklogId: $worklog->jira_worklog_id,
                timeEntryIds: [],
            );
        }

        ksort($items);

        return new JiraSyncPlanDto(
            startDate: $startDate,
            endDate: $endDate,
            items: array_values($items),
            skipped: $this->describeSkipped($timeEntries, $grouping->skipped),
        );
    }

    /**
     * Per time entry sync state for the indicators on the calendar, the time list and the
     * timesheet.
     *
     * @return array<string, array{state: string, issue_key: string|null, reason: string|null}>
     */
    public function statusFor(User $user, Organization $organization, string $startDate, string $endDate): array
    {
        $syncFromDate = $this->connectionFor($user, $organization)?->sync_from_date?->format('Y-m-d');
        $timeEntries = $this->timeEntriesForRange($user, $organization, $startDate, $endDate);
        $grouping = $this->grouper->group($timeEntries, $user->timezone, $this->config->projectKeys($organization), $syncFromDate);
        $worklogs = $this->worklogsForRange($user, $organization, max($startDate, $syncFromDate ?? $startDate), $endDate);

        $statuses = [];

        foreach ($grouping->skipped as $timeEntryId => $reason) {
            $statuses[$timeEntryId] = [
                // Only a work entry without a key is something to fix. A break or a running
                // timer is simply not a candidate, and should not raise a red dot.
                'state' => $reason === JiraSkipReason::NoIssueKey ? 'no_reference' : 'ignored',
                'issue_key' => null,
                'reason' => $reason->value,
            ];
        }

        foreach ($grouping->groups as $group) {
            $existing = $worklogs->get($group->groupHash);
            $state = match (true) {
                $existing === null => 'pending',
                $this->isUpToDate($existing, $group) => 'synced',
                default => 'outdated',
            };

            foreach ($group->timeEntryIds as $timeEntryId) {
                $statuses[$timeEntryId] = [
                    'state' => $state,
                    'issue_key' => $group->issueKey,
                    'reason' => null,
                ];
            }
        }

        return $statuses;
    }

    /**
     * Carries out a plan. One failing item does not stop the rest - a typo'd issue key should
     * not block the other four hours of the week from reaching Jira.
     *
     * @param  callable(int, int, array<string, mixed>): void|null  $onProgress  Called after each item with (done, total, result)
     * @return list<array<string, mixed>>
     */
    public function execute(User $user, Organization $organization, JiraSyncPlanDto $plan, ?callable $onProgress = null): array
    {
        $connection = $this->requireConnection($user, $organization);
        $items = $plan->actionableItems();
        $total = count($items);
        $results = [];
        $done = 0;

        foreach ($items as $item) {
            try {
                $this->applyItem($connection, $user, $organization, $item);
                $result = $item->toArray() + ['status' => 'done', 'error' => null];
            } catch (JiraAuthenticationFailedApiException $e) {
                // The credentials themselves are bad, so every remaining item would fail the
                // same way. Flag the connection and stop.
                $connection->requires_reauthentication = true;
                $connection->save();

                throw $e;
            } catch (JiraRequestFailedApiException $e) {
                $result = $item->toArray() + ['status' => 'failed', 'error' => $e->getTranslatedMessage()];
            }

            $results[] = $result;
            $done++;
            if ($onProgress !== null) {
                $onProgress($done, $total, $result);
            }
        }

        return $results;
    }

    private function applyItem(JiraConnection $connection, User $user, Organization $organization, JiraSyncItemDto $item): void
    {
        if ($item->action === JiraSyncAction::Delete) {
            if ($item->jiraWorklogId !== null) {
                $this->client->deleteWorklog($connection, $item->issueKey, $item->jiraWorklogId);
            }
            JiraWorklog::query()
                ->where('organization_id', '=', $organization->getKey())
                ->where('user_id', '=', $user->getKey())
                ->where('group_hash', '=', $item->groupHash)
                ->delete();

            return;
        }

        // Create and Update both need a start; only a Delete is allowed to omit it
        $startedAt = $item->startedAt ?? CarbonImmutable::now($user->timezone);

        if ($item->action === JiraSyncAction::Update && $item->jiraWorklogId !== null) {
            $this->client->updateWorklog(
                $connection,
                $item->issueKey,
                $item->jiraWorklogId,
                $item->comment,
                $startedAt,
                $item->durationSeconds,
            );
            $worklogId = $item->jiraWorklogId;
        } else {
            $worklogId = $this->client->createWorklog(
                $connection,
                $item->issueKey,
                $item->comment,
                $startedAt,
                $item->durationSeconds,
            );
        }

        /*
         * This update took over a worklog stored under a different hash, so the row that hash
         * points at has to go. Left behind it would be an orphan holding the same
         * jira_worklog_id, and the next sync - finding no group for it - would delete the very
         * worklog just updated. The unique index is on the hash, not the worklog id, so nothing
         * else would have caught it.
         */
        if ($item->previousGroupHash !== null && $item->previousGroupHash !== $item->groupHash) {
            JiraWorklog::query()
                ->where('organization_id', '=', $organization->getKey())
                ->where('user_id', '=', $user->getKey())
                ->where('group_hash', '=', $item->previousGroupHash)
                ->delete();
        }

        JiraWorklog::query()->updateOrCreate(
            [
                'organization_id' => $organization->getKey(),
                'user_id' => $user->getKey(),
                'group_hash' => $item->groupHash,
            ],
            [
                'issue_key' => $item->issueKey,
                'work_date' => $item->workDate,
                'comment' => $item->comment,
                'jira_worklog_id' => $worklogId,
                'duration_seconds' => $item->durationSeconds,
                'started_at' => $startedAt->utc(),
                'synced_at' => CarbonImmutable::now(),
            ],
        );
    }

    /**
     * Compared to the second: Jira stores worklog starts at second precision, and solidtime's
     * timestamps have no sub-second part either.
     */
    private function isUpToDate(JiraWorklog $worklog, JiraWorklogGroupDto $group): bool
    {
        return $worklog->duration_seconds === $group->durationSeconds
            && $worklog->started_at->equalTo($group->startedAt->utc());
    }

    /**
     * @return Collection<int, TimeEntry>
     */
    private function timeEntriesForRange(User $user, Organization $organization, string $startDate, string $endDate): Collection
    {
        $timezone = $user->timezone;
        // The range comes from the calendar as local dates, but starts are stored in UTC
        $start = CarbonImmutable::parse($startDate, $timezone)->startOfDay()->utc();
        $end = CarbonImmutable::parse($endDate, $timezone)->endOfDay()->utc();

        return TimeEntry::query()
            ->whereBelongsTo($organization, 'organization')
            ->where('user_id', '=', $user->getKey())
            ->whereBetween('start', [$start, $end])
            ->orderBy('start')
            ->get();
    }

    /**
     * @return Collection<string, JiraWorklog> Keyed by group hash
     */
    private function worklogsForRange(User $user, Organization $organization, string $startDate, string $endDate): Collection
    {
        return JiraWorklog::query()
            ->where('organization_id', '=', $organization->getKey())
            ->where('user_id', '=', $user->getKey())
            ->whereBetween('work_date', [$startDate, $endDate])
            ->get()
            ->keyBy('group_hash');
    }

    /**
     * @param  Collection<int, TimeEntry>  $timeEntries
     * @param  array<string, JiraSkipReason>  $skipped
     * @return list<JiraSkippedEntryDto>
     */
    private function describeSkipped(Collection $timeEntries, array $skipped): array
    {
        $described = [];

        foreach ($timeEntries as $timeEntry) {
            $reason = $skipped[$timeEntry->getKey()] ?? null;
            if ($reason === null) {
                continue;
            }

            $described[] = new JiraSkippedEntryDto(
                timeEntryId: $timeEntry->getKey(),
                description: $timeEntry->description,
                start: $timeEntry->start->toIso8601ZuluString(),
                durationSeconds: (int) ($timeEntry->getDuration()->totalSeconds ?? 0),
                reason: $reason,
            );
        }

        return $described;
    }
}
