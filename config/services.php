<?php

declare(strict_types=1);

return [
    'gotenberg' => [
        'url' => env('GOTENBERG_URL'),
        'basic_auth_username' => env('GOTENBERG_BASIC_AUTH_USERNAME'),
        'basic_auth_password' => env('GOTENBERG_BASIC_AUTH_PASSWORD'),
    ],

    'jira' => [
        /*
         * Replaces the Jira API client with App\Service\Jira\FakeJiraClient, an in-process
         * stand-in that lets the end to end suite connect an account and sync worklogs with no
         * Atlassian site and no outbound request at all.
         *
         * This is the only place the flag is read from the environment - env() is forbidden
         * outside config/*.php - and it is not sufficient on its own: FakeJiraClient::isEnabled()
         * also refuses to switch on when the application environment is production, so setting
         * this in a production deployment changes nothing. Leave it unset anywhere real.
         */
        'fake' => (bool) env('JIRA_FAKE_CLIENT', false),

        /*
         * Atlassian OAuth 2.0 (3LO). One app per instance, which every customer authorises
         * against - the same shape as the Google client below. Without it the Jira integration
         * stays hidden, exactly as Google Calendar does without its client.
         */
        'client_id' => env('JIRA_CLIENT_ID'),
        'client_secret' => env('JIRA_CLIENT_SECRET'),
        // Resolved against APP_URL by the controller, as Socialite does for Google.
        'redirect' => '/integrations/jira/callback',
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        // Socialite resolves a relative redirect against APP_URL
        'redirect' => '/integrations/google-calendar/callback',
    ],
];
