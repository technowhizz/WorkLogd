<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Enums\BillingInterval;
use App\Models\Organization;
use App\Service\EntitlementService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirect;

/**
 * What an organization pays, from the customer's side.
 *
 * Deliberately thin: Stripe Checkout collects the money and the Billing Portal handles cards,
 * invoices and cancellation, so neither is rebuilt here. What this owns is choosing a plan and
 * showing where the organization currently stands.
 */
class BillingController extends Controller
{
    private const SUBSCRIPTION = 'default';

    /**
     * @throws AuthorizationException
     */
    public function show(): Response
    {
        $organization = $this->currentOrganization();
        $this->requireBillingPermission($organization);

        $subscription = $organization->subscription(self::SUBSCRIPTION);
        $seats = $organization->realUsers()->count();

        return Inertia::render('Billing', [
            'organization' => [
                'id' => $organization->getKey(),
                'name' => $organization->name,
            ],
            'seats' => $seats,
            'currency' => config('billing.currency', 'GBP'),
            'prices' => [
                'monthly' => config('billing.prices.professional.monthly'),
                'yearly' => config('billing.prices.professional.yearly'),
            ],
            'subscription' => $subscription === null ? null : [
                'status' => $subscription->stripe_status,
                'price' => $subscription->stripe_price,
                'quantity' => $subscription->quantity,
                'interval' => $this->intervalOf($subscription->stripe_price)?->value,
                'is_active' => $subscription->active(),
                'on_grace_period' => $subscription->onGracePeriod(),
                'ends_at' => $subscription->ends_at?->toIso8601ZuluString(),
            ],
            // Whether the plan is doing anything yet. With enforcement off the organization keeps
            // full access however this reads, and saying so is better than implying otherwise.
            'enforced' => (bool) config('billing.enforce', false),
            'entitled' => app(EntitlementService::class)->isPaid($organization),
        ]);
    }

    /**
     * Send the organization to Stripe Checkout for the chosen interval.
     *
     * @throws AuthorizationException
     */
    public function checkout(Request $request): SymfonyRedirect|RedirectResponse
    {
        $organization = $this->currentOrganization();
        $this->requireBillingPermission($organization);

        $validated = $request->validate([
            'interval' => ['required', 'string', 'in:monthly,yearly'],
        ]);

        $price = config('billing.prices.professional.'.$validated['interval']);

        if (! is_string($price) || $price === '') {
            return back()->with([
                'bannerText' => 'No Stripe price is configured for that plan yet.',
                'bannerStyle' => 'danger',
            ]);
        }

        // Seats are charged as they stand at checkout. Adding people later pushes the quantity up
        // and Stripe prorates, so this number does not have to be a guess about the future.
        $seats = max(1, $organization->realUsers()->count());

        if ($organization->subscribed(self::SUBSCRIPTION)) {
            // Already paying, so this is a change of interval rather than a new subscription -
            // sending them through Checkout again would open a second one.
            $organization->subscription(self::SUBSCRIPTION)->swap($price);

            return redirect()->route('billing.show')->with([
                'bannerText' => 'Your plan has been updated.',
                'bannerStyle' => 'success',
            ]);
        }

        return $organization
            ->newSubscription(self::SUBSCRIPTION, $price)
            ->quantity($seats)
            ->checkout([
                'success_url' => route('billing.show').'?checkout=success',
                'cancel_url' => route('billing.show').'?checkout=cancelled',
            ])
            ->redirect();
    }

    /**
     * Hand off to Stripe's hosted portal for cards, invoices and cancellation.
     *
     * @throws AuthorizationException
     */
    public function portal(): SymfonyRedirect|RedirectResponse
    {
        $organization = $this->currentOrganization();
        $this->requireBillingPermission($organization);

        if ($organization->stripe_id === null) {
            return back()->with([
                'bannerText' => 'There is nothing to manage yet - this organization has never been billed.',
                'bannerStyle' => 'danger',
            ]);
        }

        return $organization->redirectToBillingPortal(route('billing.show'));
    }

    /**
     * @throws AuthorizationException
     */
    private function requireBillingPermission(Organization $organization): void
    {
        if (! $this->hasPermission($organization, 'billing')) {
            throw new AuthorizationException;
        }
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
