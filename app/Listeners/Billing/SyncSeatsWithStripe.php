<?php

declare(strict_types=1);

namespace App\Listeners\Billing;

use App\Events\MemberAdded;
use App\Events\MemberMadeToPlaceholder;
use App\Events\MemberRemoved;
use App\Models\Organization;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\ApiErrorException;

/**
 * Keeps the seats an organization is charged for in step with the members it actually has.
 *
 * Queued on purpose. Inviting somebody should not fail, or wait, because Stripe is slow or
 * briefly unreachable - the membership is the fact, and the billing catches up. A failure here
 * lands in the failed jobs table, which the admin portal shows.
 */
class SyncSeatsWithStripe implements ShouldQueue
{
    public function handle(MemberAdded|MemberRemoved|MemberMadeToPlaceholder $event): void
    {
        $this->sync($event->organization);
    }

    public function sync(Organization $organization): void
    {
        $subscription = $organization->subscription('default');

        // Nothing to bill against. A free organization has no Stripe subscription at all, and one
        // that has cancelled should not be charged for seats it is only using out its notice on.
        if ($subscription === null || ! $subscription->active() || $subscription->canceled()) {
            return;
        }

        $seats = max(1, $organization->realUsers()->count());

        if ($subscription->quantity === $seats) {
            return;
        }

        try {
            // Stripe prorates the change against the current period, so growing mid-month costs
            // the part of the month it is used for rather than a whole one.
            $subscription->updateQuantity($seats);
        } catch (ApiErrorException $exception) {
            Log::error('Could not update the Stripe seat count', [
                'organization_id' => $organization->getKey(),
                'seats' => $seats,
                'message' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }
}
