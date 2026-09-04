<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Controllers\Web\Admin\ImpersonationController;
use App\Models\User;
use App\Service\BillingContract;
use App\Service\EntitlementService;
use App\Service\GoogleCalendar\GoogleCalendarConfig;
use App\Service\Jira\JiraConfig;
use Illuminate\Http\Request;
use Inertia\Middleware;
use Nwidart\Modules\Facades\Module;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Defines the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $hasInvoicing = Module::has('Invoicing') && Module::isEnabled('Invoicing');
        $hasServices = Module::has('Services') && Module::isEnabled('Services');

        /** @var BillingContract $billing */
        $billing = app(BillingContract::class);

        $user = $request->user();
        $currentOrganization = $user?->currentOrganization;
        $impersonatorId = $request->session()->get(ImpersonationController::SESSION_KEY);

        return array_merge(parent::share($request), [
            // Single source of truth for the product name in the UI, so renaming the app is a
            // one line change rather than a hunt through every Vue file
            'app_name' => config('app.name'),
            // Whether to offer a way into the admin portal at all. The portal itself re-checks -
            // this only decides whether the link is drawn.
            'is_super_admin' => $user instanceof User && $user->isSuperAdmin() && $user->hasVerifiedEmail(),
            // Set while an admin is signed in as somebody else, so the app can say so and offer
            // the way back. Null the rest of the time.
            'impersonating' => is_string($impersonatorId) ? [
                'name' => $user?->name,
            ] : null,
            // Billing is first-party now rather than solidtime's private extension, so this is
            // no longer a question of whether a module is installed. Left under the same key so
            // the frontend's isBillingActivated() keeps working.
            'has_billing_extension' => true,
            'has_invoicing_extension' => $hasInvoicing,
            'has_services_extension' => $hasServices,
            // Configured on this instance *and* included in the current organization's plan -
            // both have to be true before the integration is offered.
            'google_calendar_enabled' => app(GoogleCalendarConfig::class)->isConfigured()
                && ($currentOrganization === null || app(EntitlementService::class)->allowsGoogleCalendar($currentOrganization)),
            // Available on the instance but not on this plan, so the UI can offer the upgrade
            // rather than silently hiding a feature the customer may have come for.
            'google_calendar_requires_upgrade' => app(GoogleCalendarConfig::class)->isConfigured()
                && $currentOrganization !== null
                && ! app(EntitlementService::class)->allowsGoogleCalendar($currentOrganization),
            // Per organization rather than per installation: an admin points it at their Jira
            // site, and members of an organization without one never see the integration
            'jira_enabled' => $currentOrganization !== null && app(JiraConfig::class)->isConfigured($currentOrganization),
            'billing' => $currentOrganization !== null ? [
                'has_subscription' => $billing->hasSubscription($currentOrganization),
                'has_trial' => $billing->hasTrial($currentOrganization),
                'trial_until' => $billing->getTrialUntil($currentOrganization)?->toIso8601ZuluString(),
                'is_blocked' => $billing->isBlocked($currentOrganization),
            ] : null,
            'flash' => [
                'message' => fn () => $request->session()->get('message'),
                'bannerText' => fn () => $request->session()->get('bannerText'),
                'bannerStyle' => fn () => $request->session()->get('bannerStyle'),
            ],
        ]);
    }
}
