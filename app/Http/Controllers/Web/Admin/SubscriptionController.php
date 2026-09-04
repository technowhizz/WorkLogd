<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Admin;

use App\Enums\BillingInterval;
use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionStatus;
use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Service\BillingOverviewService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class SubscriptionController extends Controller
{
    private const SORTABLE = ['plan', 'status', 'price', 'trial_ends_at', 'ends_at', 'created_at'];

    public function index(Request $request): Response
    {
        [$sort, $direction] = $this->sort($request, self::SORTABLE, 'created_at');
        $search = $this->search($request);
        $plan = $request->string('plan')->toString();
        $status = $request->string('status')->toString();
        $trials = $request->string('trials')->toString();

        $subscriptions = OrganizationSubscription::query()
            // withCount on the nested relation, so the seats column does not run a count per row.
            ->with(['organization' => fn ($query) => $query->withCount('realUsers')])
            ->when($search !== null, function (Builder $query) use ($search): void {
                /** @var Builder<OrganizationSubscription> $query */
                $query->whereHas('organization', function (Builder $query) use ($search): void {
                    /** @var Builder<Organization> $query */
                    $query->where('name', 'ilike', '%'.$search.'%');
                });
            })
            ->when($plan !== '', fn (Builder $query) => $query->where('plan', '=', $plan))
            ->when($status !== '', fn (Builder $query) => $query->where('status', '=', $status))
            ->when($trials === 'ending', function (Builder $query): void {
                /** @var Builder<OrganizationSubscription> $query */
                $query->where('status', '=', SubscriptionStatus::Trialing->value)
                    ->whereNotNull('trial_ends_at')
                    ->whereBetween('trial_ends_at', [Carbon::now(), Carbon::now()->addWeek()]);
            })
            ->orderBy($sort, $direction)
            ->orderBy($this->tiebreaker())
            ->paginate($this->perPage($request))
            ->withQueryString()
            ->through(fn (OrganizationSubscription $subscription): array => $this->toListItem($subscription));

        return Inertia::render('Admin/Subscriptions', [
            'subscriptions' => $subscriptions,
            'filters' => $this->filters($request, ['plan', 'status', 'trials']),
            'stats' => app(BillingOverviewService::class)->stats(),
            'options' => $this->options(),
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('Admin/SubscriptionEdit', [
            'subscription' => null,
            'organizationId' => $request->string('organization')->toString() ?: null,
            'organizations' => $this->organizationsWithoutSubscription(),
            'options' => $this->options(),
        ]);
    }

    public function edit(OrganizationSubscription $subscription): Response
    {
        // preventAccessingMissingAttributes is on outside production, so the count the list item
        // reads has to be loaded here too.
        $subscription->load(['organization' => fn ($query) => $query->withCount('realUsers')]);

        return Inertia::render('Admin/SubscriptionEdit', [
            'subscription' => array_merge($this->toListItem($subscription), [
                'external_reference' => $subscription->external_reference,
                'note' => $subscription->note,
                'starts_at' => $subscription->starts_at?->format('Y-m-d\TH:i'),
                'trial_ends_at_input' => $subscription->trial_ends_at?->format('Y-m-d\TH:i'),
                'ends_at_input' => $subscription->ends_at?->format('Y-m-d\TH:i'),
            ]),
            'organizationId' => $subscription->organization_id,
            'organizations' => [[
                'id' => $subscription->organization_id,
                'name' => $subscription->organization->name,
            ]],
            'options' => $this->options(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validateSubscription($request, true);

        $existing = OrganizationSubscription::query()
            ->where('organization_id', '=', $validated['organization_id'])
            ->exists();

        if ($existing) {
            return $this->backWithError('That organization already has a subscription. Edit the existing one instead.');
        }

        $subscription = new OrganizationSubscription;
        $subscription->organization_id = $validated['organization_id'];
        $this->fill($subscription, $validated);
        $subscription->save();

        return redirect()->route('admin.subscriptions.index')->with([
            'bannerText' => 'Subscription created.',
            'bannerStyle' => 'success',
        ]);
    }

    public function update(Request $request, OrganizationSubscription $subscription): RedirectResponse
    {
        $validated = $this->validateSubscription($request, false);
        $this->fill($subscription, $validated);
        $subscription->save();

        return $this->back('Subscription updated.');
    }

    public function destroy(OrganizationSubscription $subscription): RedirectResponse
    {
        $subscription->delete();

        return redirect()->route('admin.subscriptions.index')->with([
            'bannerText' => 'Subscription deleted.',
            'bannerStyle' => 'success',
        ]);
    }

    public function startTrial(OrganizationSubscription $subscription): RedirectResponse
    {
        $subscription->status = SubscriptionStatus::Trialing;
        $subscription->trial_ends_at = Carbon::now()->addDays((int) config('billing.trial_days', 14));
        $subscription->save();

        return $this->back('Trial started, running until '.$subscription->trial_ends_at->toFormattedDateString().'.');
    }

    /**
     * The billing record an organization has been on all along, so the screen has something to
     * edit for an organization nothing has been recorded against yet.
     */
    public function ensureFor(Organization $organization): RedirectResponse
    {
        $subscription = $organization->billingRecord()->first();

        if ($subscription === null) {
            $subscription = new OrganizationSubscription;
            $subscription->organization()->associate($organization);
            $subscription->currency = $organization->currency;
            $subscription->save();
        }

        return redirect()->route('admin.subscriptions.edit', ['subscription' => $subscription->getKey()]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateSubscription(Request $request, bool $withOrganization): array
    {
        $rules = [
            'plan' => ['required', 'string'],
            'status' => ['required', 'string'],
            'trial_ends_at' => ['nullable', 'date'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date'],
            'seats' => ['nullable', 'integer', 'gt:0', 'max:2147483647'],
            'price' => ['nullable', 'integer', 'gte:0', 'max:2147483647'],
            'currency' => ['nullable', 'string', 'size:3'],
            'billing_interval' => ['nullable', 'string'],
            'external_reference' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string'],
        ];

        if ($withOrganization) {
            $rules['organization_id'] = ['required', 'uuid', 'exists:organizations,id'];
        }

        return $request->validate($rules);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function fill(OrganizationSubscription $subscription, array $validated): void
    {
        $subscription->plan = SubscriptionPlan::from($validated['plan']);
        $subscription->status = SubscriptionStatus::from($validated['status']);
        $subscription->trial_ends_at = $validated['trial_ends_at'] === null ? null : Carbon::parse($validated['trial_ends_at']);
        $subscription->starts_at = $validated['starts_at'] === null ? null : Carbon::parse($validated['starts_at']);
        $subscription->ends_at = $validated['ends_at'] === null ? null : Carbon::parse($validated['ends_at']);
        $subscription->seats = $validated['seats'];
        $subscription->price = $validated['price'];
        $subscription->currency = $validated['currency'];
        $subscription->billing_interval = $validated['billing_interval'] === null
            ? null
            : BillingInterval::from($validated['billing_interval']);
        $subscription->external_reference = $validated['external_reference'];
        $subscription->note = $validated['note'];
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function organizationsWithoutSubscription(): array
    {
        return Organization::query()
            ->whereDoesntHave('billingRecord')
            ->orderBy('name')
            ->limit(500)
            ->get(['id', 'name'])
            ->map(fn (Organization $organization): array => [
                'id' => $organization->getKey(),
                'name' => $organization->name,
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function options(): array
    {
        return [
            'plans' => SubscriptionPlan::toSelectArray(),
            'statuses' => SubscriptionStatus::toSelectArray(),
            'intervals' => BillingInterval::toSelectArray(),
            'currencies' => $this->currencyOptions(),
            'trial_days' => (int) config('billing.trial_days', 14),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function toListItem(OrganizationSubscription $subscription): array
    {
        $organization = $subscription->organization;

        return [
            'id' => $subscription->getKey(),
            'organization_id' => $subscription->organization_id,
            'organization_name' => $organization->name,
            'plan' => $subscription->plan->value,
            'status' => $subscription->status->value,
            'is_active' => $subscription->isActive(),
            'is_on_trial' => $subscription->isOnTrial(),
            'seats' => $subscription->seats,
            'members_count' => $organization->real_users_count,
            'price' => $subscription->price,
            'currency' => $subscription->currency,
            'billing_interval' => $subscription->billing_interval?->value,
            'monthly_price' => $subscription->monthlyPrice(),
            'trial_ends_at' => $subscription->trial_ends_at?->toIso8601ZuluString(),
            'ends_at' => $subscription->ends_at?->toIso8601ZuluString(),
            'created_at' => $subscription->created_at?->toIso8601ZuluString(),
        ];
    }
}
