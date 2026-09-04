<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Enforcement
    |--------------------------------------------------------------------------
    |
    | Whether the subscription records managed in the admin panel actually decide what an
    | organization may do. With this off - the default, and what a self-hosted instance
    | wants - every organization keeps full access and the records are only bookkeeping.
    |
    */

    'enforce' => (bool) env('BILLING_ENFORCE', false),

    /*
    |--------------------------------------------------------------------------
    | Default trial length
    |--------------------------------------------------------------------------
    |
    | How many days a trial started from the admin portal runs for.
    |
    | Self-serve signup does not start a trial - the free tier is the trial. This exists for the
    | sales case: giving a specific organization a paid look at the product by hand.
    |
    */

    'trial_days' => (int) env('BILLING_TRIAL_DAYS', 14),

    /*
    |--------------------------------------------------------------------------
    | Default currency
    |--------------------------------------------------------------------------
    |
    | The currency new subscriptions are priced in, and the one the revenue totals in the
    | admin panel are reported in.
    |
    */

    'currency' => env('BILLING_CURRENCY', 'GBP'),

    /*
    |--------------------------------------------------------------------------
    | What the free tier gets
    |--------------------------------------------------------------------------
    |
    | Applied only while enforcement is on. A null limit means no limit.
    |
    | The Jira allowance counts worklogs *created* in Jira during the current week, not every
    | write - editing or removing a worklog you have already pushed does not spend it again,
    | which would otherwise make correcting a description a reason to run out.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Stripe prices
    |--------------------------------------------------------------------------
    |
    | The price IDs the checkout sends people to. Per seat, so the quantity is the organization's
    | real member count. The amounts live in Stripe rather than here, which is what lets you
    | change them without a deploy.
    |
    | Enterprise has no price on purpose: those deals are negotiated and recorded by hand in the
    | admin portal, which already carries a custom price, seat count, interval and an invoice
    | reference for however the money is actually collected.
    |
    */

    'prices' => [
        'professional' => [
            'monthly' => env('STRIPE_PRICE_PROFESSIONAL_MONTHLY'),
            'yearly' => env('STRIPE_PRICE_PROFESSIONAL_YEARLY'),
        ],
    ],

    'free' => [
        'jira_worklogs_per_week' => env('BILLING_FREE_JIRA_WORKLOGS_PER_WEEK', 5),
        'google_calendar' => (bool) env('BILLING_FREE_GOOGLE_CALENDAR', false),
    ],

];
