<?php

declare(strict_types=1);

namespace App\Listeners\Billing;

use App\Enums\BillingInterval;
use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionStatus;
use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Service\BillingContract;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Events\WebhookHandled;

/**
 * Mirrors Stripe's view of a subscription onto the organization's own billing record.
 *
 * Two layers on purpose. Cashier's `subscriptions` table is Stripe's state, kept in step by
 * webhooks. `OrganizationSubscription` is what this instance believes an organization is entitled
 * to, and it is what {@see BillingContract} reads - which is how an enterprise deal
 * settled by invoice, or an account comped by hand, works at all. Stripe drives the record when
 * Stripe is involved; nothing stops an admin owning it when Stripe is not.
 *
 * Runs after Cashier has finished with the event, so its own tables are already up to date.
 */
class SyncSubscriptionFromStripe
{
    public function handle(WebhookHandled $event): void
    {
        $type = $event->payload['type'] ?? null;

        if (! is_string($type) || ! str_starts_with($type, 'customer.subscription.')) {
            return;
        }

        $stripeId = $event->payload['data']['object']['customer'] ?? null;

        if (! is_string($stripeId)) {
            return;
        }

        /** @var Organization|null $organization */
        $organization = Organization::query()->where('stripe_id', '=', $stripeId)->first();

        if ($organization === null) {
            // A customer this instance does not know about. Worth a line in the log - it usually
            // means two environments are pointed at the same Stripe account.
            Log::warning('Stripe webhook for an unknown customer', [
                'type' => $type,
                'stripe_customer' => $stripeId,
            ]);

            return;
        }

        $this->apply($organization);
    }

    /**
     * Write what Cashier now holds onto the organization's billing record.
     */
    public function apply(Organization $organization): void
    {
        $subscription = $organization->subscription('default');

        $record = OrganizationSubscription::query()
            ->where('organization_id', '=', $organization->getKey())
            ->first();

        if ($record === null) {
            $record = new OrganizationSubscription;
            $record->organization()->associate($organization);
        }

        if ($subscription === null) {
            // Never subscribed, or the subscription is gone entirely.
            $record->plan = SubscriptionPlan::Free;
            $record->status = SubscriptionStatus::Expired;
            $record->save();

            return;
        }

        // Cashier dates come back as Carbon\Carbon; this application types its own on
        // Illuminate\Support\Carbon, so they are converted rather than assigned across.
        $endsAt = $subscription->ends_at === null ? null : Carbon::instance($subscription->ends_at);
        $trialEndsAt = $subscription->trial_ends_at === null ? null : Carbon::instance($subscription->trial_ends_at);

        $record->plan = SubscriptionPlan::Professional;
        $record->status = $this->statusFrom($subscription->stripe_status, $endsAt);
        $record->seats = $subscription->quantity;
        $record->currency = mb_strtoupper((string) config('cashier.currency', 'gbp'));
        $record->billing_interval = $this->intervalOf($subscription->stripe_price);
        $record->ends_at = $endsAt;
        $record->trial_ends_at = $trialEndsAt;
        $record->external_reference = $subscription->stripe_id;
        $record->starts_at ??= Carbon::now();
        $record->save();
    }

    /**
     * Stripe's status vocabulary onto this application's.
     *
     * A cancelled subscription that is still inside the period it was paid for stays entitled -
     * `ends_at` is what says when that stops, and isActive() already honours it.
     */
    private function statusFrom(string $stripeStatus, ?Carbon $endsAt): SubscriptionStatus
    {
        return match ($stripeStatus) {
            'active', 'trialing' => $endsAt !== null ? SubscriptionStatus::Cancelled : SubscriptionStatus::Active,
            'past_due', 'unpaid' => SubscriptionStatus::PastDue,
            'canceled' => $endsAt !== null && $endsAt->isFuture()
                ? SubscriptionStatus::Cancelled
                : SubscriptionStatus::Expired,
            default => SubscriptionStatus::Expired,
        };
    }

    private function intervalOf(?string $price): ?BillingInterval
    {
        if ($price === null) {
            return null;
        }

        return match ($price) {
            config('billing.prices.professional.monthly') => BillingInterval::Monthly,
            config('billing.prices.professional.yearly') => BillingInterval::Yearly,
            default => null,
        };
    }
}
