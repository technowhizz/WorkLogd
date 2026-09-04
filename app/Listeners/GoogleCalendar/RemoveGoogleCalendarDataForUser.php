<?php

declare(strict_types=1);

namespace App\Listeners\GoogleCalendar;

use App\Events\BeforeUserDeletion;
use App\Models\GoogleCalendarConnection;

/**
 * The connection is deleted rather than revoked at Google, because the account is going away
 * entirely - there is nothing left for a revoked token to protect, and a network call inside the
 * deletion transaction is a way for a delete to fail for reasons that have nothing to do with it.
 */
class RemoveGoogleCalendarDataForUser
{
    public function handle(BeforeUserDeletion $event): void
    {
        GoogleCalendarConnection::query()->whereBelongsTo($event->user, 'user')->delete();
    }
}
