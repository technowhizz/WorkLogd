<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Admin;

use App\Models\Audit;
use App\Models\FailedJob;
use App\Models\OrganizationInvitation;
use App\Models\Passport\Token;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The screens for looking at what the instance did rather than at what is in it - the audit trail,
 * the jobs that failed, the tokens in circulation and the invitations still outstanding.
 */
class SystemController extends Controller
{
    public function audits(Request $request): Response
    {
        [$sort, $direction] = $this->sort($request, ['created_at', 'event', 'auditable_type'], 'created_at');
        $search = $this->search($request);
        $event = $request->string('event')->toString();

        $audits = Audit::query()
            ->with('user')
            ->when($search !== null, function (Builder $query) use ($search): void {
                /** @var Builder<Audit> $query */
                $query->where(function (Builder $query) use ($search): void {
                    /** @var Builder<Audit> $query */
                    $query->where('auditable_type', 'ilike', '%'.$search.'%')
                        ->orWhere('auditable_id', '=', $search)
                        ->orWhere('ip_address', '=', $search);
                });
            })
            ->when($event !== '', fn (Builder $query) => $query->where('event', '=', $event))
            ->orderBy($sort, $direction)
            ->orderBy($this->tiebreaker())
            ->paginate($this->perPage($request))
            ->withQueryString()
            ->through(fn (Audit $audit): array => [
                'id' => $audit->getKey(),
                'event' => $audit->event,
                'auditable_type' => $audit->auditable_type,
                'auditable_id' => $audit->auditable_id,
                'user_name' => $audit->user?->name,
                'user_id' => $audit->user_id,
                'url' => $audit->url,
                'ip_address' => $audit->ip_address,
                'old_values' => $audit->old_values,
                'new_values' => $audit->new_values,
                'created_at' => $audit->created_at?->toIso8601ZuluString(),
            ]);

        return Inertia::render('Admin/Audits', [
            'audits' => $audits,
            'filters' => $this->filters($request, ['event']),
            'eventOptions' => [
                'created' => 'Created',
                'updated' => 'Updated',
                'deleted' => 'Deleted',
                'restored' => 'Restored',
            ],
        ]);
    }

    public function failedJobs(Request $request): Response
    {
        [$sort, $direction] = $this->sort($request, ['failed_at', 'queue'], 'failed_at');
        $search = $this->search($request);

        $jobs = FailedJob::query()
            ->when($search !== null, function (Builder $query) use ($search): void {
                /** @var Builder<FailedJob> $query */
                $query->where('exception', 'ilike', '%'.$search.'%')
                    ->orWhere('queue', 'ilike', '%'.$search.'%');
            })
            ->orderBy($sort, $direction)
            ->orderBy($this->tiebreaker())
            ->paginate($this->perPage($request))
            ->withQueryString()
            ->through(fn (FailedJob $job): array => [
                'uuid' => $job->uuid,
                'connection' => $job->connection,
                'queue' => $job->queue,
                'failed_at' => $job->failed_at->toIso8601ZuluString(),
                // The first line is the class and message, which is the part worth showing in a
                // table; the rest is the stack trace and stays behind the detail toggle.
                'summary' => str($job->getAttribute('exception'))->explode("\n")->first(),
                'exception' => $job->getAttribute('exception'),
            ]);

        return Inertia::render('Admin/FailedJobs', [
            'jobs' => $jobs,
            'filters' => $this->filters($request),
        ]);
    }

    public function retryFailedJob(string $uuid): RedirectResponse
    {
        Artisan::call('queue:retry', ['id' => [$uuid]]);

        return $this->back('Job queued for another attempt.');
    }

    public function deleteFailedJob(string $uuid): RedirectResponse
    {
        FailedJob::query()->where('uuid', '=', $uuid)->delete();

        return $this->back('Failed job deleted.');
    }

    public function tokens(Request $request): Response
    {
        [$sort, $direction] = $this->sort($request, ['created_at', 'expires_at', 'name'], 'created_at');
        $search = $this->search($request);
        $state = $request->string('state')->toString();

        $tokens = Token::query()
            ->with('user')
            ->when($search !== null, function (Builder $query) use ($search): void {
                /** @var Builder<Token> $query */
                $query->where('name', 'ilike', '%'.$search.'%')
                    ->orWhereHas('user', function (Builder $query) use ($search): void {
                        /** @var Builder<User> $query */
                        $query->where('email', 'ilike', '%'.$search.'%');
                    });
            })
            ->when($state === 'active', function (Builder $query): void {
                /** @var Builder<Token> $query */
                $query->where('revoked', '=', false)
                    ->where(function (Builder $query): void {
                        /** @var Builder<Token> $query */
                        $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
                    });
            })
            ->when($state === 'revoked', fn (Builder $query) => $query->where('revoked', '=', true))
            ->orderBy($sort, $direction)
            ->orderBy($this->tiebreaker())
            ->paginate($this->perPage($request))
            ->withQueryString()
            ->through(fn (Token $token): array => [
                'id' => $token->getKey(),
                'name' => $token->name,
                'user_email' => $token->user?->email,
                'user_id' => $token->user_id,
                'revoked' => $token->revoked,
                'scopes' => $token->scopes,
                'expires_at' => $token->expires_at?->toIso8601ZuluString(),
                'created_at' => $token->created_at?->toIso8601ZuluString(),
            ]);

        return Inertia::render('Admin/Tokens', [
            'tokens' => $tokens,
            'filters' => $this->filters($request, ['state']),
        ]);
    }

    public function revokeToken(string $token): RedirectResponse
    {
        Token::query()->whereKey($token)->update(['revoked' => true]);

        return $this->back('Token revoked.');
    }

    public function invitations(Request $request): Response
    {
        [$sort, $direction] = $this->sort($request, ['created_at', 'email', 'role'], 'created_at');
        $search = $this->search($request);
        $state = $request->string('state')->toString();

        $invitations = OrganizationInvitation::query()
            ->with('organization')
            ->when($search !== null, fn (Builder $query) => $query->where('email', 'ilike', '%'.$search.'%'))
            ->when($state === 'pending', fn (Builder $query) => $query->whereNull('accepted_at'))
            ->when($state === 'accepted', fn (Builder $query) => $query->whereNotNull('accepted_at'))
            ->orderBy($sort, $direction)
            ->orderBy($this->tiebreaker())
            ->paginate($this->perPage($request))
            ->withQueryString()
            ->through(fn (OrganizationInvitation $invitation): array => [
                'id' => $invitation->getKey(),
                'email' => $invitation->email,
                'role' => $invitation->role,
                'organization_id' => $invitation->organization_id,
                'organization_name' => $invitation->organization->name,
                'accepted_at' => $invitation->accepted_at?->toIso8601ZuluString(),
                'created_at' => $invitation->created_at?->toIso8601ZuluString(),
            ]);

        return Inertia::render('Admin/Invitations', [
            'invitations' => $invitations,
            'filters' => $this->filters($request, ['state']),
        ]);
    }

    public function deleteInvitation(OrganizationInvitation $invitation): RedirectResponse
    {
        $email = $invitation->email;
        $invitation->delete();

        return $this->back('Invitation to '.$email.' withdrawn.');
    }
}
