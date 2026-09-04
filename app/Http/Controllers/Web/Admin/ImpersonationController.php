<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Admin;

use App\Models\User;
use App\Service\UserService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Signing in as another user to see what they see.
 *
 * The admin's own id is parked in the session for the duration, which is both how the banner knows
 * to offer a way back and how the way back is authorised - nothing else is trusted to say who was
 * really signed in.
 */
class ImpersonationController extends Controller
{
    public const SESSION_KEY = 'impersonator_id';

    public function start(Request $request, User $user): RedirectResponse
    {
        if (! $user->canBeImpersonated()) {
            return $this->backWithError('Placeholder users cannot be impersonated - nobody signs in as them.');
        }

        if ($user->is($this->user())) {
            return $this->backWithError('You are already signed in as yourself.');
        }

        if ($request->session()->has(self::SESSION_KEY)) {
            return $this->backWithError('Stop the current impersonation before starting another.');
        }

        // Somewhere to land. A user with no current organization would otherwise arrive at a
        // dashboard that cannot render, which looks like the impersonation itself broke.
        if ($user->currentOrganization === null) {
            $organization = $user->organizations()->where('personal_team', '=', true)->first()
                ?? $user->organizations()->first();

            if ($organization === null) {
                return $this->backWithError('"'.$user->name.'" belongs to no organization, so there is nothing to see.');
            }

            app(UserService::class)->switchCurrentOrganization($user, $organization);
        }

        $impersonatorId = $this->user()->getKey();

        Auth::guard('web')->login($user);
        // Laravel cycles the session id on login; putting the key back afterwards is what keeps it.
        $request->session()->put(self::SESSION_KEY, $impersonatorId);

        return redirect()->route('dashboard');
    }

    public function stop(Request $request): RedirectResponse
    {
        $impersonatorId = $request->session()->pull(self::SESSION_KEY);

        if (! is_string($impersonatorId)) {
            return redirect()->route('dashboard');
        }

        /** @var User|null $impersonator */
        $impersonator = User::query()->whereKey($impersonatorId)->first();

        if ($impersonator === null || ! $impersonator->isSuperAdmin()) {
            Auth::guard('web')->logout();

            return redirect()->route('login');
        }

        Auth::guard('web')->login($impersonator);

        return redirect()->route('admin.users.index');
    }
}
