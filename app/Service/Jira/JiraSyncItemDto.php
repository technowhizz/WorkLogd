<?php

declare(strict_types=1);

namespace App\Service\Jira;

use Carbon\CarbonImmutable;

/**
 * One thing the sync will do to one Jira worklog.
 */
class JiraSyncItemDto
{
    /**
     * @param  int|null  $previousDurationSeconds  What Jira currently holds, for an update
     * @param  list<string>  $timeEntryIds  Empty for a delete - its entries are gone
     * @param  string|null  $previousGroupHash  Set when this update took over a worklog that was
     *                                          stored under a different hash, because the
     *                                          description changed. The row keyed by it has to be
     *                                          dropped as this one is written, or the next sync
     *                                          finds an orphan pointing at the same Jira worklog
     *                                          and deletes what was just updated.
     */
    public function __construct(
        public readonly JiraSyncAction $action,
        public readonly string $issueKey,
        public readonly string $workDate,
        public readonly ?string $comment,
        public readonly string $groupHash,
        public readonly int $durationSeconds,
        public readonly ?int $previousDurationSeconds,
        public readonly ?CarbonImmutable $startedAt,
        public readonly ?string $jiraWorklogId,
        public readonly array $timeEntryIds,
        public readonly ?string $previousGroupHash = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'action' => $this->action->value,
            'issue_key' => $this->issueKey,
            'work_date' => $this->workDate,
            'comment' => $this->comment,
            'group_hash' => $this->groupHash,
            'duration' => $this->durationSeconds,
            'previous_duration' => $this->previousDurationSeconds,
            'started' => $this->startedAt?->utc()->toIso8601ZuluString(),
            'time_entry_ids' => $this->timeEntryIds,
        ];
    }
}
