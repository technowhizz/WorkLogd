<?php

declare(strict_types=1);

namespace App\Service;

use App\Models\Organization;
use App\Models\OrganizationSubscription;
use Illuminate\Support\Carbon;

/**
 * Answers the billing questions from the subscription records kept in the admin panel.
 *
 * Enforcement is off unless `billing.enforce` says otherwise, and while it is off every answer
 * here is the permissive one from {@see BillingContract}. That keeps a self-hosted instance
 * working exactly as it did before any of this existed: an operator can record what each
 * organization pays without those records ever shutting anybody out, and only turns enforcement
 * on once the records are right.
 */
class SubscriptionBillingService extends BillingContract
{
    public function hasSubscription(Organization $organization): bool
    {
        if (! $this->isEnforced()) {
            return parent::hasSubscription($organization);
        }

        return $this->subscriptionFor($organization)?->isActive() ?? false;
    }

    public function hasTrial(Organization $organization): bool
    {
        if (! $this->isEnforced()) {
            return parent::hasTrial($organization);
        }

        return $this->subscriptionFor($organization)?->isOnTrial() ?? false;
    }

    public function getTrialUntil(Organization $organization): ?Carbon
    {
        if (! $this->isEnforced()) {
            return parent::getTrialUntil($organization);
        }

        $subscription = $this->subscriptionFor($organization);

        return $subscription !== null && $subscription->isOnTrial() ? $subscription->trial_ends_at : null;
    }

    public function isBlocked(Organization $organization): bool
    {
        if (! $this->isEnforced()) {
            return parent::isBlocked($organization);
        }

        if ($this->hasSubscription($organization) || $this->hasTrial($organization)) {
            return false;
        }

        // A single-member organization is never blocked, however it is paying. Blocking is about
        // the members a free organization is not entitled to, and locking out the one person who
        // could go and fix the billing would help nobody.
        return $this->countRealMembers($organization) > 1;
    }

    /**
     * How many members an organization is over the seats it has paid for, if it has a seat count
     * and is over it.
     *
     * Nothing is blocked on this - it is what the admin panel uses to show which organizations
     * need a conversation about their plan.
     */
    public function countMembersOverSeats(Organization $organization): int
    {
        $subscription = $this->subscriptionFor($organization);

        if ($subscription === null || $subscription->seats === null) {
            return 0;
        }

        return max(0, $this->countRealMembers($organization) - $subscription->seats);
    }

    public function isEnforced(): bool
    {
        return (bool) config('billing.enforce', false);
    }

    private function subscriptionFor(Organization $organization): ?OrganizationSubscription
    {
        if ($organization->relationLoaded('billingRecord')) {
            return $organization->billingRecord;
        }

        return $organization->billingRecord()->first();
    }

    private function countRealMembers(Organization $organization): int
    {
        return $organization->realUsers()->count();
    }
}
