<?php

declare(strict_types=1);

namespace App\Listeners\Jira;

use App\Events\BeforeOrganizationDeletion;
use App\Models\JiraConnection;
use App\Models\JiraWorklog;
use App\Models\JiraWorklogCreation;

/**
 * Removes an organization's Jira credentials and the record of what was synced.
 *
 * Note the worklogs themselves stay in Jira - they are that organization's record of work done,
 * and deleting an organization here should not rewrite history over there.
 *
 * Lives as a listener rather than inside DeletionService so that everything Jira knows about sits
 * on one side of the seam, and core never has to name a Jira table to delete an organization.
 */
class RemoveJiraDataForOrganization
{
    public function handle(BeforeOrganizationDeletion $event): void
    {
        JiraConnection::query()->whereBelongsTo($event->organization, 'organization')->delete();
        JiraWorklog::query()->whereBelongsTo($event->organization, 'organization')->delete();
        // The allowance ledger goes with the organization it belonged to. It is append-only for
        // the organization's own lifetime, not beyond it.
        JiraWorklogCreation::query()->whereBelongsTo($event->organization, 'organization')->delete();
    }
}
