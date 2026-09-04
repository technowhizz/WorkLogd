<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Models\JiraConnection;
use App\Models\Organization;
use App\Models\User;
use App\Service\Jira\FakeJiraClient;
use App\Service\Jira\JiraConfig;
use App\Service\Jira\JiraOAuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirectResponse;
use Throwable;

/**
 * Connecting a Jira account over OAuth 2.0 (3LO).
 *
 * Lives in the web routes rather than the API because the authorization round trip needs the
 * session: the `state` parameter is what proves the callback belongs to the person who started
 * the flow, and it has nowhere else to live.
 */
class JiraConnectionController extends Controller
{
    private const STATE_KEY = 'jira_oauth_state';

    private const ORGANIZATION_KEY = 'jira_oauth_organization';

    public function connect(Request $request): SymfonyRedirectResponse|RedirectResponse
    {
        $user = $this->user();
        $organization = $user->currentOrganization ?? $user->organizations()->first();
        $config = app(JiraConfig::class);

        if ($organization === null || ! $config->isConfigured($organization)) {
            return $this->back('This organization has no Jira site configured yet.');
        }

        /*
         * The browser suite drives the real button and needs to come back connected, which a
         * round trip to Atlassian cannot give it. Gated on the same flag as FakeJiraClient, which
         * refuses to switch on in production - see FakeJiraClient::isEnabled().
         */
        if (FakeJiraClient::isEnabled()) {
            $this->storeConnection($user, $organization, [
                'access_token' => 'fake-access-token',
                'refresh_token' => 'fake-refresh-token',
                'expires_in' => 3600,
            ], 'fake-cloud-id');

            return redirect()->route('profile.show')->with([
                'bannerText' => 'Jira connected.',
                'bannerStyle' => 'success',
            ]);
        }

        if (! $config->isOAuthConfigured()) {
            return $this->back('Jira is not set up on this instance.');
        }

        $state = Str::random(40);
        $request->session()->put(self::STATE_KEY, $state);
        // Remembered rather than trusted from the callback, so the connection cannot be pointed
        // at a different organization by editing the URL on the way back.
        $request->session()->put(self::ORGANIZATION_KEY, $organization->getKey());

        return redirect()->away(app(JiraOAuthService::class)->authorizationUrl($state));
    }

    public function callback(Request $request): RedirectResponse
    {
        $user = $this->user();
        $oauth = app(JiraOAuthService::class);

        $expectedState = $request->session()->pull(self::STATE_KEY);
        $organizationId = $request->session()->pull(self::ORGANIZATION_KEY);

        if ($request->string('error')->toString() !== '') {
            // Includes the ordinary case of somebody pressing Cancel on the consent screen.
            return $this->back('Jira was not connected.');
        }

        if (! is_string($expectedState) || $request->string('state')->toString() !== $expectedState) {
            return $this->back('That Jira sign-in could not be verified. Please try again.');
        }

        /** @var Organization|null $organization */
        $organization = $organizationId === null ? null : Organization::query()->whereKey($organizationId)->first();

        if ($organization === null || ! $user->isMemberOfOrganization($organization)) {
            return $this->back('That Jira sign-in could not be matched to an organization.');
        }

        $code = $request->string('code')->toString();

        if ($code === '') {
            return $this->back('Jira did not return an authorization code.');
        }

        try {
            $tokens = $oauth->exchangeCode($code);
            $resources = $oauth->accessibleResources($tokens['access_token']);
        } catch (Throwable $exception) {
            Log::warning('Jira authorization failed', ['message' => $exception->getMessage()]);

            return $this->back('Jira could not be connected. Please try again.');
        }

        $expectedSite = app(JiraConfig::class)->siteUrl($organization);
        $resource = $this->matchingResource($resources, $expectedSite);

        if ($resource === null) {
            /*
             * The consent screen lets people choose which site to grant, and nothing stops them
             * choosing the wrong one. Refusing here is the difference between telling somebody
             * now and them discovering weeks of time logged to the wrong Jira later.
             */
            return $this->back(
                'That Atlassian site does not match this organization\'s Jira site ('.$expectedSite.'). '
                .'Please authorise again and pick that site.'
            );
        }

        $this->storeConnection($user, $organization, $tokens, $resource['id']);

        return redirect()->route('profile.show')->with([
            'bannerText' => 'Jira connected.',
            'bannerStyle' => 'success',
        ]);
    }

    /**
     * Write the connection, replacing whatever was there before.
     *
     * @param  array{access_token: string, refresh_token: string|null, expires_in: int}  $tokens
     */
    private function storeConnection(User $user, Organization $organization, array $tokens, string $cloudId): void
    {
        $connection = JiraConnection::query()
            ->where('user_id', '=', $user->getKey())
            ->where('organization_id', '=', $organization->getKey())
            ->first() ?? new JiraConnection;

        $connection->user_id = $user->getKey();
        $connection->organization_id = $organization->getKey();
        $connection->access_token = $tokens['access_token'];
        $connection->refresh_token = $tokens['refresh_token'];
        $connection->token_expires_at = Carbon::now()->addSeconds($tokens['expires_in']);
        $connection->cloud_id = $cloudId;
        $connection->requires_reauthentication = false;
        $connection->last_verified_at = Carbon::now();
        $connection->save();

        // A reconnection may well be a different Atlassian account, so the cached name goes.
        app(JiraOAuthService::class)->forgetIdentity($connection);
    }

    /**
     * The authorised site that matches the one this organization logs to.
     *
     * @param  array<int, array{id: string, url: string, name: string}>  $resources
     * @return array{id: string, url: string, name: string}|null
     */
    private function matchingResource(array $resources, ?string $expectedSite): ?array
    {
        if ($expectedSite === null) {
            return null;
        }

        foreach ($resources as $resource) {
            if (JiraConfig::normaliseSiteUrl($resource['url']) === $expectedSite) {
                return $resource;
            }
        }

        return null;
    }

    private function back(string $message): RedirectResponse
    {
        return redirect()->route('profile.show')->with([
            'bannerText' => $message,
            'bannerStyle' => 'danger',
        ]);
    }
}
