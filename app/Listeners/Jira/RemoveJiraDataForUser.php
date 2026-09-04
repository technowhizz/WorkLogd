<?php

declare(strict_types=1);

namespace App\Listeners\Jira;

use App\Events\BeforeUserDeletion;
use App\Models\JiraConnection;
use App\Models\JiraWorklog;
use App\Models\JiraWorklogCreation;

class RemoveJiraDataForUser
{
    public function handle(BeforeUserDeletion $event): void
    {
        JiraConnection::query()->whereBelongsTo($event->user, 'user')->delete();
        JiraWorklog::query()->whereBelongsTo($event->user, 'user')->delete();
        JiraWorklogCreation::query()->whereBelongsTo($event->user, 'user')->delete();
    }
}
