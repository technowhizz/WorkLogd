<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\Api\ApiException;
use App\Models\Organization;
use App\Models\User;
use App\Service\Jira\JiraSyncRunStore;
use App\Service\Jira\JiraSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pushes a date range of one user's time entries to Jira.
 *
 * Queued rather than done in the request because a week can be dozens of Jira calls, and a
 * request that times out half way through would leave Jira partly updated with nobody watching.
 */
class SyncJiraWorklogs implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public bool $deleteWhenMissingModels = true;

    /**
     * Retrying would re-plan against a Jira that the failed attempt may already have changed,
     * so a failure is reported to the user instead.
     */
    public int $tries = 1;

    /**
     * One sync per organization at a time.
     *
     * Two runs side by side each read the weekly allowance before either has spent any of it, so
     * a free organization could double it by pressing Sync twice. Overlapping runs are also a way
     * to get duplicate worklogs into Jira on their own account, since both plan against the same
     * unchanged state. Released rather than dropped, so the second run happens - just afterwards.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('jira-sync:'.$this->organization->getKey()))
                ->releaseAfter(10)
                ->expireAfter(600),
        ];
    }

    public function __construct(
        public readonly User $user,
        public readonly Organization $organization,
        public readonly string $runId,
        public readonly string $startDate,
        public readonly string $endDate,
    ) {}

    public function handle(JiraSyncService $syncService, JiraSyncRunStore $runStore): void
    {
        $runStore->running($this->user, $this->runId);

        try {
            // Re-planned here rather than carried from the preview: the entries may have moved
            // on since, and Jira should end up matching solidtime as it is now.
            $plan = $syncService->plan($this->user, $this->organization, $this->startDate, $this->endDate);

            $syncService->execute(
                $this->user,
                $this->organization,
                $plan,
                function (int $done, int $total, array $result) use ($runStore): void {
                    $runStore->progressed($this->user, $this->runId, $done, $total, $result);
                },
            );

            $runStore->completed($this->user, $this->runId);
        } catch (ApiException $e) {
            $runStore->failed($this->user, $this->runId, $e->getTranslatedMessage());
        } catch (Throwable $e) {
            Log::error('Jira sync failed', [
                'user_id' => $this->user->getKey(),
                'organization_id' => $this->organization->getKey(),
                'run_id' => $this->runId,
                'message' => $e->getMessage(),
            ]);

            $runStore->failed($this->user, $this->runId, __('exceptions.api.jira_request_failed'));
        }
    }
}
