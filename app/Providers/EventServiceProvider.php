<?php

declare(strict_types=1);

namespace App\Providers;

use App\Events\BeforeOrganizationDeletion;
use App\Events\BeforeUserDeletion;
use App\Events\MemberAdded;
use App\Events\MemberMadeToPlaceholder;
use App\Events\MemberRemoved;
use App\Listeners\Billing\SyncSeatsWithStripe;
use App\Listeners\Billing\SyncSubscriptionFromStripe;
use App\Listeners\GoogleCalendar\RemoveGoogleCalendarDataForUser;
use App\Listeners\Jira\RemoveJiraDataForOrganization;
use App\Listeners\Jira\RemoveJiraDataForUser;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Listeners\SendEmailVerificationNotification;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Laravel\Cashier\Events\WebhookHandled;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event to listener mappings for the application.
     *
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        Registered::class => [
            SendEmailVerificationNotification::class,
        ],
        /*
         * Integration data is removed by the integration, not by DeletionService. These
         * registrations move into the extension's own service provider when Jira and Google
         * Calendar are extracted - the events themselves stay in core, which is the seam.
         */
        BeforeOrganizationDeletion::class => [
            RemoveJiraDataForOrganization::class,
        ],
        // Stripe is the source of truth for money; this puts what it says onto the record the
        // rest of the app reads. Never trust the checkout redirect for this - only the webhook.
        /*
         * Seats follow membership. Queued, so an invite never fails because Stripe is briefly
         * unreachable - see SyncSeatsWithStripe.
         */
        MemberAdded::class => [
            SyncSeatsWithStripe::class,
        ],
        MemberRemoved::class => [
            SyncSeatsWithStripe::class,
        ],
        MemberMadeToPlaceholder::class => [
            SyncSeatsWithStripe::class,
        ],
        WebhookHandled::class => [
            SyncSubscriptionFromStripe::class,
        ],
        BeforeUserDeletion::class => [
            RemoveJiraDataForUser::class,
            RemoveGoogleCalendarDataForUser::class,
        ],
    ];

    /**
     * Register any events for your application.
     */
    public function boot(): void
    {
        //
    }

    /**
     * Determine if events and listeners should be automatically discovered.
     */
    public function shouldDiscoverEvents(): bool
    {
        return false;
    }
}
