<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate for the admin portal.
 *
 * Access is instance-wide and has nothing to do with a role inside an organization, so this is
 * deliberately not a PermissionStore check - see {@see User::isSuperAdmin()}.
 */
class EnsureUserIsAdmin
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // The verified email requirement matches what the panel has always asked for: an address
        // nobody has proved they own is not a credential for administering every organization.
        if (! ($user instanceof User) || ! $user->isSuperAdmin() || ! $user->hasVerifiedEmail()) {
            abort(403);
        }

        return $next($request);
    }
}
