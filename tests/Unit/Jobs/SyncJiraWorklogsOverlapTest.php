<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use App\Jobs\SyncJiraWorklogs;
use App\Models\Member;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCaseWithDatabase;

/**
 * Two syncs running side by side would each read the weekly allowance before either had spent
 * any of it, so a free organization could double it just by pressing Sync twice.
 */
#[CoversClass(SyncJiraWorklogs::class)]
class SyncJiraWorklogsOverlapTest extends TestCaseWithDatabase
{
    private function job(Organization $organization, User $user): SyncJiraWorklogs
    {
        return new SyncJiraWorklogs($user, $organization, 'run-id', '2026-08-05', '2026-08-05');
    }

    public function test_the_job_refuses_to_overlap_with_itself(): void
    {
        // Arrange
        $user = User::factory()->create();
        $organization = Organization::factory()->withOwner($user)->create();
        Member::factory()->forUser($user)->forOrganization($organization)->create();

        // Act
        $middleware = $this->job($organization, $user)->middleware();

        // Assert
        $this->assertCount(1, $middleware);
        $this->assertInstanceOf(WithoutOverlapping::class, $middleware[0]);
    }

    public function test_the_lock_is_per_organization(): void
    {
        // Arrange
        $user = User::factory()->create();
        $first = Organization::factory()->withOwner($user)->create();
        $second = Organization::factory()->withOwner($user)->create();

        // Act
        /** @var WithoutOverlapping $firstLock */
        $firstLock = $this->job($first, $user)->middleware()[0];
        /** @var WithoutOverlapping $secondLock */
        $secondLock = $this->job($second, $user)->middleware()[0];

        // Assert
        // Keyed on the organization because the allowance is the organization's. One person
        // syncing two organizations at once is fine; two syncs of the same one is not.
        $this->assertSame('jira-sync:'.$first->getKey(), $firstLock->key);
        $this->assertSame('jira-sync:'.$second->getKey(), $secondLock->key);
        $this->assertNotSame($firstLock->key, $secondLock->key);
    }

    public function test_a_blocked_run_is_released_rather_than_dropped(): void
    {
        // Arrange
        $user = User::factory()->create();
        $organization = Organization::factory()->withOwner($user)->create();

        // Act
        /** @var WithoutOverlapping $lock */
        $lock = $this->job($organization, $user)->middleware()[0];

        // Assert
        // The second sync still has to happen - just after the first, not instead of it.
        $this->assertSame(10, $lock->releaseAfter);
        $this->assertSame(600, $lock->expiresAfter);
    }
}
