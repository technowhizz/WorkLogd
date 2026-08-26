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
        $movedWorklogs = $this->worklogsMatchingGroupEntries($user, $organization, $grouping->groups, $worklogs);

        $matching = $this->matchWorklogsToGroups($grouping->groups, $worklogs, $movedWorklogs);

        $items = [];

        foreach ($grouping->groups as $group) {
            $existing = $matching['matches'][$group->groupHash] ?? null;

            if ($existing === null) {
                $items[] = new JiraSyncItemDto(
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

            // A worklog matched under another hash is stale by definition: its comment, day or
            // timezone-derived date is the old value, so there is nothing to compare and no
            // chance of "unchanged".
            $takenOver = $existing->group_hash !== $group->groupHash;

            $items[] = new JiraSyncItemDto(
                action: ! $takenOver && $this->isUpToDate($existing, $group)
                    ? JiraSyncAction::Unchanged
                    : JiraSyncAction::Update,
                issueKey: $group->issueKey,
                workDate: $group->workDate,
                comment: $group->comment,
                groupHash: $group->groupHash,
                durationSeconds: $group->durationSeconds,
                previousDurationSeconds: $existing->duration_seconds,
                startedAt: $group->startedAt,
                jiraWorklogId: $existing->jira_worklog_id,
                timeEntryIds: $group->timeEntryIds,
                worklogRowId: $existing->getKey(),
            );
        }

        // Anything solidtime logged in this range that no longer corresponds to a group: its
        // entries were deleted, or edited onto a different ticket.
        foreach ($matching['orphans'] as $worklog) {
            $items[] = new JiraSyncItemDto(
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
                worklogRowId: $worklog->getKey(),
            );
        }

        return new JiraSyncPlanDto(
            startDate: $startDate,
            endDate: $endDate,
            items: $items,
            skipped: $this->describeSkipped($timeEntries, $grouping->skipped),
        );
    }

    /**
     * Which worklog each group corresponds to, and which worklogs correspond to nothing.
     *
     * A worklog's identity is the entries that formed it, not its wording: time_entry_ids is
     * recorded when it is written, so any edit that changes the group hash - a reword, a date
     * moved, a timezone change re-dating history - still finds the same worklog and updates it in
     * place. Jira treats a delete-and-recreate as a different worklog (new id, new place in the
     * issue's history, anything attached to it gone), so matching errs towards updating.
     *
     * Two passes, the second over what the first left unmatched:
     *
     *  1. Exact group hash - unchanged content, the common case. Running this first is also what
     *     makes rewriting a row's hash in place safe against the unique index: an update can only
     *     want a hash no unmatched row holds, since a row holding it would have matched here. It
     *     also covers an entry deleted and retyped identically, whose ids differ but content does
     *     not.
     *  2. Entry-id overlap, same issue key required - Jira's API cannot move a worklog between
     *     issues, so a ticket change genuinely is delete-and-create. Pairs are taken best-first:
     *     most shared entries, then matching comment, then matching day, so when one worklog's
     *     entries split into two groups the untouched group keeps the worklog and the edited one
     *     creates. Deterministic order throughout, so the same data always plans the same way.
     *
     * There was once a third pass - a ticket-and-day heuristic for rows from before ids were
     * stored. It retired itself: every sync stamps ids onto the rows it sees, and once no
     * id-less row remained it matched nothing. A row without ids today simply hash-matches
     * while unchanged, is stamped by its next sync, and orphans if edited before one runs.
     *
     * Shared by plan() and statusFor() so the preview and the dots cannot disagree.
     *
     * @param  list<JiraWorklogGroupDto>  $groups
     * @param  Collection<string, JiraWorklog>  $rangedWorklogs  Rows inside the reconciled date
     *                                                           range, keyed by group hash. Only
     *                                                           these may become orphans: the
     *                                                           range is the reconciliation
     *                                                           boundary, and a range sync must
     *                                                           not delete things outside it.
     * @param  Collection<string, JiraWorklog>  $movedWorklogs  Rows found by entry id whose
     *                                                          work_date fell outside the range -
     *                                                          their entries moved. Matchable,
     *                                                          never orphaned.
     * @return array{matches: array<string, JiraWorklog>, orphans: list<JiraWorklog>}
     */
    private function matchWorklogsToGroups(array $groups, Collection $rangedWorklogs, Collection $movedWorklogs): array
    {
        /** @var Collection<string, JiraWorklog> $worklogs */
        $worklogs = $rangedWorklogs->union($movedWorklogs);

        /** @var array<string, JiraWorklog> $matches */
        $matches = [];
        /** @var array<string, true> $matchedRowIds */
        $matchedRowIds = [];

        // Pass 1: exact hash
        $unmatchedGroups = [];
        foreach ($groups as $group) {
            $existing = $worklogs->get($group->groupHash);
            if ($existing === null) {
                $unmatchedGroups[] = $group;

                continue;
            }

            $matches[$group->groupHash] = $existing;
            $matchedRowIds[$existing->getKey()] = true;
        }

        // Pass 2: shared entries on the same issue, best pair first
        $spare = $worklogs->reject(fn (JiraWorklog $worklog): bool => isset($matchedRowIds[$worklog->getKey()]));
        $pairs = [];
        foreach ($unmatchedGroups as $groupIndex => $group) {
            $groupIds = array_flip($group->timeEntryIds);
            foreach ($spare as $worklog) {
                if ($worklog->issue_key !== $group->issueKey || $worklog->time_entry_ids === null) {
                    continue;
                }
                $overlap = count(array_intersect_key($groupIds, array_flip($worklog->time_entry_ids)));
                if ($overlap === 0) {
                    continue;
                }

                $pairs[] = [
                    'rank' => [
                        -$overlap,
                        $worklog->comment === $group->comment ? 0 : 1,
                        $worklog->work_date->format('Y-m-d') === $group->workDate ? 0 : 1,
                        $groupIndex,
                        $worklog->jira_worklog_id,
                    ],
                    'groupIndex' => $groupIndex,
                    'worklog' => $worklog,
                ];
            }
        }
        usort($pairs, static fn (array $a, array $b): int => $a['rank'] <=> $b['rank']);

        foreach ($pairs as $pair) {
            $group = $unmatchedGroups[$pair['groupIndex']] ?? null;
            if ($group === null || isset($matchedRowIds[$pair['worklog']->getKey()])) {
                continue;
            }

            $matches[$group->groupHash] = $pair['worklog'];
            $matchedRowIds[$pair['worklog']->getKey()] = true;
            unset($unmatchedGroups[$pair['groupIndex']]);
        }

        $orphans = [];
        foreach ($rangedWorklogs as $worklog) {
            if (! isset($matchedRowIds[$worklog->getKey()])) {
                $orphans[] = $worklog;
            }
        }

        return ['matches' => $matches, 'orphans' => $orphans];
    }

    /**
     * Worklogs whose recorded entries appear in the current groups but whose work_date fell
     * outside the reconciled range - the signature of an entry moved to another day, by hand or by
     * a timezone change. Without this a moved entry looks brand new (create) while its old worklog
     * waits invisibly to be orphaned whenever the old range is next synced: a transient duplicate
     * in Jira. Found by the GIN-indexed ?| containment operator, written ??| because PDO would
     * otherwise read the ? as a placeholder.
     *
     * @param  list<JiraWorklogGroupDto>  $groups
     * @param  Collection<string, JiraWorklog>  $alreadyFetched  Keyed by group hash
     * @return Collection<string, JiraWorklog> Keyed by group hash
     */
    private function worklogsMatchingGroupEntries(User $user, Organization $organization, array $groups, Collection $alreadyFetched): Collection
    {
        $entryIds = array_merge(...array_map(
            static fn (JiraWorklogGroupDto $group): array => $group->timeEntryIds,
            $groups,
        ) ?: [[]]);
        if ($entryIds === []) {
            /** @var Collection<string, JiraWorklog> */
            return new Collection;
        }

        // UUIDs only - no quoting or escaping applies inside the array literal
        $pgArray = '{'.implode(',', $entryIds).'}';

        return JiraWorklog::query()
            ->where('organization_id', '=', $organization->getKey())
            ->where('user_id', '=', $user->getKey())
            ->whereRaw('time_entry_ids ??| ?::text[]', [$pgArray])
            ->whereNotIn('group_hash', $alreadyFetched->keys())
            ->get()
            ->keyBy('group_hash');
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

        // The same pairing the plan uses, so a reworded or re-dated entry reads as outdated - it
        // has a live worklog that is merely stale - rather than as never having been logged.
        $movedWorklogs = $this->worklogsMatchingGroupEntries($user, $organization, $grouping->groups, $worklogs);
        $matching = $this->matchWorklogsToGroups($grouping->groups, $worklogs, $movedWorklogs);

        /*
         * Entries some worklog remembers being built from. A group the matcher could not pair can
         * still contain them - the one edit matching refuses to follow is a ticket change, since
         * Jira cannot move a worklog between issues. To the person looking at the dot that entry
         * is not new work: it was logged, and they changed it. "Outdated" is the truthful state;
         * "pending" would hide that the edit disturbed something already in Jira.
         */
        $previouslyLoggedEntryIds = [];
        foreach ([$worklogs, $movedWorklogs] as $collection) {
            foreach ($collection as $worklog) {
                foreach ($worklog->time_entry_ids ?? [] as $timeEntryId) {
                    $previouslyLoggedEntryIds[$timeEntryId] = true;
                }
            }
        }

        foreach ($grouping->groups as $group) {
            $existing = $matching['matches'][$group->groupHash] ?? null;
            $wasLoggedBefore = $existing === null
                && array_intersect_key(array_flip($group->timeEntryIds), $previouslyLoggedEntryIds) !== [];
            $state = match (true) {
                $wasLoggedBefore => 'outdated',
                $existing === null => 'pending',
                $existing->group_hash !== $group->groupHash => 'outdated',
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

        $this->stampMembershipOnUnchangedRows($user, $organization, $plan);

        return $results;
    }

    /**
     * Backfills time_entry_ids onto rows from before the column existed.
     *
     * Creates and updates record membership as they write, but an untouched worklog never reaches
     * applyItem - actionableItems() filters it - so a pre-migration row that is simply up to date
     * would stay id-less forever. Stamping it here means one ordinary sync upgrades every
     * live row.
     */
    private function stampMembershipOnUnchangedRows(User $user, Organization $organization, JiraSyncPlanDto $plan): void
    {
        $membershipByRowId = [];
        foreach ($plan->items as $item) {
            if ($item->action === JiraSyncAction::Unchanged && $item->worklogRowId !== null) {
                $membershipByRowId[$item->worklogRowId] = $item->timeEntryIds;
            }
        }
        if ($membershipByRowId === []) {
            return;
        }

        $rows = JiraWorklog::query()
            ->where('organization_id', '=', $organization->getKey())
            ->where('user_id', '=', $user->getKey())
            ->whereKey(array_keys($membershipByRowId))
            ->whereNull('time_entry_ids')
            ->get();

        foreach ($rows as $row) {
            $row->time_entry_ids = $membershipByRowId[$row->getKey()];
            $row->save();
        }
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
                ->whereKey($item->worklogRowId)
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

        $attributes = [
            'issue_key' => $item->issueKey,
            'work_date' => $item->workDate,
            'comment' => $item->comment,
            'group_hash' => $item->groupHash,
            'time_entry_ids' => $item->timeEntryIds,
            'jira_worklog_id' => $worklogId,
            'duration_seconds' => $item->durationSeconds,
            'started_at' => $startedAt->utc(),
            'synced_at' => CarbonImmutable::now(),
        ];

        /*
         * An update rewrites the exact row it matched, whatever hash that row was stored under -
         * the row is the worklog's identity here, and its hash, date and comment are just
         * attributes being brought up to date. This is what removes the old failure mode where a
         * reworded group wrote a second row and left the first behind as an orphan holding the
         * same jira_worklog_id, to be "cleaned up" - deleting the live worklog - a sync later.
         */
        $row = $item->worklogRowId === null
            ? null
            : JiraWorklog::query()
                ->where('organization_id', '=', $organization->getKey())
                ->where('user_id', '=', $user->getKey())
                ->whereKey($item->worklogRowId)
                ->first();

        if ($row !== null) {
            $row->fill($attributes);
            $row->save();

            return;
        }

        // A create, or the matched row vanished underneath us: keyed on the hash so a retried
        // create after a half-failed run updates its own leftover instead of violating the index.
        JiraWorklog::query()->updateOrCreate(
            [
                'organization_id' => $organization->getKey(),
                'user_id' => $user->getKey(),
                'group_hash' => $item->groupHash,
            ],
            $attributes,
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
