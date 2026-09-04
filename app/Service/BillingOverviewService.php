<?php

declare(strict_types=1);

namespace App\Service;

use App\Models\Organization;
use App\Models\OrganizationSubscription;
use Illuminate\Support\Carbon;

/**
 * The instance-wide billing figures the admin portal reports.
 *
 * Kept out of the pages themselves because both the overview and the billing screen show them, and
 * the trimmed set on the overview must agree with the full set on the billing screen.
 */
class BillingOverviewService
{
    /**
     * @return array<string, mixed>
     */
    public function stats(): array
    {
        /** @var string $reportingCurrency */
        $reportingCurrency = config('billing.currency', 'EUR');

        $subscriptions = OrganizationSubscription::query()->get();

        $paying = $subscriptions->filter(fn (OrganizationSubscription $s): bool => $s->isActive());
        $trialing = $subscriptions->filter(fn (OrganizationSubscription $s): bool => $s->isOnTrial());

        $inReportingCurrency = $paying->filter(
            fn (OrganizationSubscription $s): bool => $s->currency === $reportingCurrency
        );

        $trialsEndingSoon = $trialing->filter(
            fn (OrganizationSubscription $s): bool => $s->trial_ends_at !== null
                && $s->trial_ends_at->isBefore(Carbon::now()->addWeek())
        )->count();

        $lapsed = $subscriptions->filter(fn (OrganizationSubscription $s): bool => ! $s->isActive()
            && ! $s->isOnTrial()
            && $s->plan->isPaid())->count();

        return [
            'currency' => $reportingCurrency,
            'monthly_revenue' => $inReportingCurrency->sum(
                fn (OrganizationSubscription $s): int => $s->monthlyPrice() ?? 0
            ),
            'paying' => $paying->count(),
            // Revenue in other currencies is left out rather than converted, because converting
            // needs a rate and a date and this figure is not worth either.
            'other_currencies' => $paying->count() - $inReportingCurrency->count(),
            'trialing' => $trialing->count(),
            'trials_ending_soon' => $trialsEndingSoon,
            'lapsed' => $lapsed,
            'unbilled' => Organization::query()->whereDoesntHave('billingRecord')->count(),
            'enforced' => (bool) config('billing.enforce', false),
        ];
    }
}
