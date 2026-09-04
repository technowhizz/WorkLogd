<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Admin;

use App\Enums\Weekday;
use App\Exceptions\Api\ApiException;
use App\Models\User;
use App\Service\DeletionService;
use App\Service\TimezoneService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    private const SORTABLE = ['name', 'email', 'created_at', 'updated_at'];

    public function index(Request $request): Response
    {
        [$sort, $direction] = $this->sort($request, self::SORTABLE, 'created_at');
        $search = $this->search($request);
        $type = $request->string('type')->toString();

        $users = User::query()
            ->when($search !== null, function (Builder $query) use ($search): void {
                /** @var Builder<User> $query */
                $query->where(function (Builder $query) use ($search): void {
                    /** @var Builder<User> $query */
                    $query->where('name', 'ilike', '%'.$search.'%')
                        ->orWhere('email', 'ilike', '%'.$search.'%');
                });
            })
            ->when($type === 'real', fn (Builder $query) => $query->where('is_placeholder', '=', false))
            ->when($type === 'placeholder', fn (Builder $query) => $query->where('is_placeholder', '=', true))
            ->when($type === 'admin', fn (Builder $query) => $query->where('is_admin', '=', true))
            ->when($type === 'unverified', fn (Builder $query) => $query->whereNull('email_verified_at'))
            ->orderBy($sort, $direction)
            ->orderBy($this->tiebreaker())
            ->paginate($this->perPage($request))
            ->withQueryString()
            ->through(fn (User $user): array => $this->toListItem($user));

        return Inertia::render('Admin/Users', [
            'users' => $users,
            'filters' => $this->filters($request, ['type']),
        ]);
    }

    public function show(User $user): Response
    {
        $organizations = $user->organizations()
            ->orderBy('name')
            ->get()
            ->map(fn ($organization): array => [
                'id' => $organization->getKey(),
                'name' => $organization->name,
                'role' => $organization->membership->role,
                'is_owner' => $organization->user_id === $user->getKey(),
            ]);

        return Inertia::render('Admin/UserShow', [
            'user' => array_merge($this->toListItem($user), [
                'timezone' => $user->timezone,
                'week_start' => $user->week_start->value,
                'pending_email' => $user->pending_email,
                // The environment list cannot be revoked from in here, and neither can your own
                // access - the page greys the switch out for both, and update() enforces it.
                'is_admin_by_configuration' => $user->isSuperAdminByConfiguration(),
                'is_self' => $user->is($this->user()),
            ]),
            'organizations' => $organizations,
            'options' => [
                'timezones' => app(TimezoneService::class)->getSelectOptions(),
                'weekdays' => collect(Weekday::cases())
                    ->mapWithKeys(fn (Weekday $day): array => [$day->value => ucfirst($day->value)])
                    ->all(),
            ],
        ]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email:rfc,strict', 'max:255'],
            'timezone' => ['required', 'string', 'timezone'],
            'week_start' => ['required', 'string'],
            'is_admin' => ['required', 'boolean'],
            'is_email_verified' => ['required', 'boolean'],
            'password' => ['nullable', 'string', 'max:255'],
        ]);

        if (! $user->is_placeholder) {
            $duplicate = User::query()
                ->where('email', '=', $validated['email'])
                ->where('is_placeholder', '=', false)
                ->whereKeyNot($user->getKey())
                ->exists();

            if ($duplicate) {
                return $this->backWithError('Another account already uses '.$validated['email'].'.');
            }
        }

        $user->name = $validated['name'];
        $user->email = $validated['email'];
        $user->timezone = $validated['timezone'];
        $user->week_start = Weekday::from($validated['week_start']);

        // Revoking your own access locks you out of the portal, and revoking an environment
        // granted admin's would be undone on their next request, so neither is allowed through.
        if (! $user->is($this->user()) && ! $user->isSuperAdminByConfiguration()) {
            $user->is_admin = $validated['is_admin'];
        }

        if ($validated['is_email_verified'] && $user->email_verified_at === null) {
            $user->email_verified_at = now();
        } elseif (! $validated['is_email_verified']) {
            $user->email_verified_at = null;
        }

        if (filled($validated['password'] ?? null)) {
            $user->password = Hash::make($validated['password']);
        }

        $user->save();

        return $this->back('User "'.$user->name.'" updated.');
    }

    public function destroy(User $user): RedirectResponse
    {
        if ($user->is($this->user())) {
            return $this->backWithError('You cannot delete your own account from here.');
        }

        $name = $user->name;

        try {
            app(DeletionService::class)->deleteUser($user);
        } catch (ApiException $exception) {
            return $this->backWithError('Delete failed: '.$exception->getTranslatedMessage());
        }

        return redirect()->route('admin.users.index')->with([
            'bannerText' => 'User "'.$name.'" has been deleted.',
            'bannerStyle' => 'success',
        ]);
    }

    public function resendVerification(User $user): RedirectResponse
    {
        if ($user->hasVerifiedEmail()) {
            return $this->backWithError('That address is already verified.');
        }

        $user->sendEmailVerificationNotification();

        return $this->back('Verification email sent to '.$user->email.'.');
    }

    /**
     * @return array<string, mixed>
     */
    private function toListItem(User $user): array
    {
        return [
            'id' => $user->getKey(),
            'name' => $user->name,
            'email' => $user->email,
            'is_placeholder' => $user->is_placeholder,
            'is_admin' => $user->isSuperAdmin(),
            'email_verified' => $user->email_verified_at !== null,
            'can_be_impersonated' => $user->canBeImpersonated(),
            'created_at' => $user->created_at?->toIso8601ZuluString(),
        ];
    }
}
