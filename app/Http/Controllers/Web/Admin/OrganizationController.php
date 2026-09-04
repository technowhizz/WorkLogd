<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Admin;

use App\Enums\CurrencyFormat;
use App\Enums\DateFormat;
use App\Enums\IntervalFormat;
use App\Enums\NumberFormat;
use App\Enums\SubscriptionPlan;
use App\Enums\TimeFormat;
use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\User;
use App\Service\DeletionService;
use App\Service\Export\ExportService;
use App\Service\Import\Importers\ImporterProvider;
use App\Service\Import\Importers\ImportException;
use App\Service\Import\ImportService;
use App\Service\TimezoneService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OrganizationController extends Controller
{
    private const SORTABLE = ['name', 'created_at', 'updated_at'];

    public function index(Request $request): Response
    {
        [$sort, $direction] = $this->sort($request, self::SORTABLE, 'created_at');
        $search = $this->search($request);
        $plan = $request->string('plan')->toString();

        $organizations = Organization::query()
            ->with(['owner', 'billingRecord'])
            ->withCount('realUsers')
            ->when($search !== null, function (Builder $query) use ($search): void {
                /** @var Builder<Organization> $query */
                $query->where(function (Builder $query) use ($search): void {
                    /** @var Builder<Organization> $query */
                    $query->where('name', 'ilike', '%'.$search.'%')
                        ->orWhereHas('owner', function (Builder $query) use ($search): void {
                            /** @var Builder<User> $query */
                            $query->where('email', 'ilike', '%'.$search.'%');
                        });
                });
            })
            ->when($plan === 'none', function (Builder $query): void {
                /** @var Builder<Organization> $query */
                $query->whereDoesntHave('billingRecord');
            })
            ->when($plan !== '' && $plan !== 'none', function (Builder $query) use ($plan): void {
                /** @var Builder<Organization> $query */
                $query->whereHas('billingRecord', function (Builder $query) use ($plan): void {
                    /** @var Builder<OrganizationSubscription> $query */
                    $query->where('plan', '=', $plan);
                });
            })
            ->orderBy($sort, $direction)
            ->orderBy($this->tiebreaker())
            ->paginate($this->perPage($request))
            ->withQueryString()
            ->through(fn (Organization $organization): array => $this->toListItem($organization));

        return Inertia::render('Admin/Organizations', [
            'organizations' => $organizations,
            'filters' => $this->filters($request, ['plan']),
            'planOptions' => SubscriptionPlan::toSelectArray(),
        ]);
    }

    public function show(Organization $organization): Response
    {
        $organization->load(['owner', 'billingRecord'])->loadCount('realUsers');

        $members = $organization->users()
            ->orderBy('name')
            ->get()
            ->map(fn ($user): array => [
                'id' => $user->getKey(),
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->membership->role,
                'is_placeholder' => $user->is_placeholder,
            ]);

        $invitations = $organization->organizationInvitations()
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($invitation): array => [
                'id' => $invitation->getKey(),
                'email' => $invitation->email,
                'role' => $invitation->role,
                'accepted_at' => $invitation->accepted_at?->toIso8601ZuluString(),
                'created_at' => $invitation->created_at?->toIso8601ZuluString(),
            ]);

        $subscription = $organization->billingRecord()->first();

        return Inertia::render('Admin/OrganizationShow', [
            'organization' => array_merge($this->toListItem($organization), [
                'personal_team' => $organization->personal_team,
                'billable_rate' => $organization->billable_rate,
                'date_format' => $organization->date_format->value,
                'time_format' => $organization->time_format->value,
                'interval_format' => $organization->interval_format->value,
                'number_format' => $organization->number_format->value,
                'currency_format' => $organization->currency_format->value,
                'employees_can_see_billable_rates' => $organization->employees_can_see_billable_rates,
                'employees_can_manage_tasks' => $organization->employees_can_manage_tasks,
                'prevent_overlapping_time_entries' => $organization->prevent_overlapping_time_entries,
                'breaks_enabled' => $organization->breaks_enabled,
                'jira_site_url' => $organization->jira_site_url,
            ]),
            'members' => $members,
            'invitations' => $invitations,
            'subscriptionId' => $subscription?->getKey(),
            'options' => [
                'currencies' => $this->currencyOptions(),
                'date_format' => DateFormat::toSelectArray(),
                'time_format' => TimeFormat::toSelectArray(),
                'interval_format' => IntervalFormat::toSelectArray(),
                'number_format' => NumberFormat::toSelectArray(),
                'currency_format' => CurrencyFormat::toSelectArray(),
                'importers' => app(ImporterProvider::class)->getImporterKeys(),
                'timezones' => app(TimezoneService::class)->getSelectOptions(),
            ],
        ]);
    }

    public function update(Request $request, Organization $organization): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'currency' => ['required', 'string', 'size:3'],
            'billable_rate' => ['nullable', 'integer', 'gt:0', 'max:2147483647'],
            'date_format' => ['required', 'string'],
            'time_format' => ['required', 'string'],
            'interval_format' => ['required', 'string'],
            'number_format' => ['required', 'string'],
            'currency_format' => ['required', 'string'],
            'employees_can_see_billable_rates' => ['required', 'boolean'],
            'employees_can_manage_tasks' => ['required', 'boolean'],
            'prevent_overlapping_time_entries' => ['required', 'boolean'],
            'breaks_enabled' => ['required', 'boolean'],
        ]);

        $organization->name = $validated['name'];
        $organization->currency = $validated['currency'];
        $organization->billable_rate = $validated['billable_rate'];
        $organization->date_format = DateFormat::from($validated['date_format']);
        $organization->time_format = TimeFormat::from($validated['time_format']);
        $organization->interval_format = IntervalFormat::from($validated['interval_format']);
        $organization->number_format = NumberFormat::from($validated['number_format']);
        $organization->currency_format = CurrencyFormat::from($validated['currency_format']);
        $organization->employees_can_see_billable_rates = $validated['employees_can_see_billable_rates'];
        $organization->employees_can_manage_tasks = $validated['employees_can_manage_tasks'];
        $organization->prevent_overlapping_time_entries = $validated['prevent_overlapping_time_entries'];
        $organization->breaks_enabled = $validated['breaks_enabled'];
        $organization->save();

        return $this->back('Organization "'.$organization->name.'" updated.');
    }

    public function destroy(Organization $organization): RedirectResponse
    {
        $name = $organization->name;
        app(DeletionService::class)->deleteOrganization($organization);

        return redirect()->route('admin.organizations.index')->with([
            'bannerText' => 'Organization "'.$name.'" and everything in it has been deleted.',
            'bannerStyle' => 'success',
        ]);
    }

    public function export(Organization $organization): StreamedResponse|RedirectResponse
    {
        try {
            $file = app(ExportService::class)->export($organization);
        } catch (\Exception $exception) {
            report($exception);

            return $this->backWithError('Export failed: '.$exception->getMessage());
        }

        return response()->streamDownload(function () use ($file): void {
            echo Storage::disk(config('filesystems.private'))->get($file);
        }, 'export.zip');
    }

    public function import(Request $request, Organization $organization): RedirectResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'file'],
            'type' => ['required', 'string'],
            'timezone' => ['required', 'string', 'timezone'],
        ]);

        $contents = file_get_contents($request->file('file')->getRealPath());
        if ($contents === false) {
            return $this->backWithError('Import failed: the uploaded file could not be read.');
        }

        try {
            $report = app(ImportService::class)->import(
                $organization,
                $validated['type'],
                $contents,
                $validated['timezone']
            );
        } catch (ImportException $exception) {
            report($exception);

            return $this->backWithError('Import failed and every change was rolled back: '.$exception->getMessage());
        }

        return $this->back(
            'Imported '.$report->timeEntriesCreated.' time entries, '.$report->projectsCreated.' projects, '
            .$report->clientsCreated.' clients, '.$report->tasksCreated.' tasks, '.$report->tagsCreated.' tags and '
            .$report->usersCreated.' users.'
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function toListItem(Organization $organization): array
    {
        $subscription = $organization->billingRecord;

        return [
            'id' => $organization->getKey(),
            'name' => $organization->name,
            'currency' => $organization->currency,
            'owner_email' => $organization->owner->email,
            'personal_team' => $organization->personal_team,
            'members_count' => $organization->real_users_count,
            'plan' => $subscription?->plan->value,
            'status' => $subscription?->status->value,
            'created_at' => $organization->created_at?->toIso8601ZuluString(),
        ];
    }
}
