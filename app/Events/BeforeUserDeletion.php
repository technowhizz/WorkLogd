<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired before a user and everything belonging to them is deleted.
 *
 * The counterpart to {@see BeforeOrganizationDeletion}, and the seam an extension uses to remove
 * its own rows: a user-owned table outside core has to be cleaned up before the user row goes, or
 * the restrictOnDelete foreign key stops the deletion.
 */
class BeforeUserDeletion
{
    use Dispatchable;

    public function __construct(
        public User $user,
    ) {}
}
