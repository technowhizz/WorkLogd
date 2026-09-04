<?php

declare(strict_types=1);

namespace App\Service\Jira;

use App\Exceptions\Api\JiraAuthenticationFailedApiException;
use App\Exceptions\Api\JiraNotConfiguredApiException;
use App\Models\JiraConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Atlassian OAuth 2.0 (3LO).
 *
 * Replaces the long-lived API tokens this integration used to keep. An access token lasts about
 * an hour and the refresh token rotates on every use, so a stolen credential is worth far less
 * than a token that stayed valid until somebody noticed.
 */
class JiraOAuthService
{
    private const AUTHORIZE_URL = 'https://auth.atlassian.com/authorize';

    private const TOKEN_URL = 'https://auth.atlassian.com/oauth/token';

    private const RESOURCES_URL = 'https://api.atlassian.com/oauth/token/accessible-resources';

    private const IDENTITY_URL = 'https://api.atlassian.com/me';

    /**
     * How long the connected account's name and email are held.
     *
     * Deliberately far under a day. Atlassian's personal data declaration treats anything cached
     * for longer than 24 hours as stored, and this application stores no Atlassian identity at
     * all - the settings screen asks for it when it renders and forgets it again.
     */
    private const IDENTITY_CACHE_SECONDS = 900;

    private const TIMEOUT_SECONDS = 10;

    public function __construct(private readonly JiraConfig $config) {}

    /**
     * Where to send somebody to authorise.
     *
     * `prompt=consent` is what makes Atlassian return a refresh token on a repeat authorisation
     * rather than only the first one.
     */
    public function authorizationUrl(string $state): string
    {
        $this->requireConfiguration();

        return self::AUTHORIZE_URL.'?'.http_build_query([
            'audience' => 'api.atlassian.com',
            'client_id' => $this->config->clientId(),
            'scope' => implode(' ', JiraConfig::SCOPES),
            'redirect_uri' => $this->redirectUri(),
            'state' => $state,
            'response_type' => 'code',
            'prompt' => 'consent',
        ]);
    }

    public function redirectUri(): string
    {
        /** @var string $path */
        $path = config('services.jira.redirect', '/integrations/jira/callback');

        return str_starts_with($path, 'http') ? $path : rtrim((string) config('app.url'), '/').$path;
    }

    /**
     * Trade the authorization code for tokens.
     *
     * @return array{access_token: string, refresh_token: string|null, expires_in: int}
     */
    public function exchangeCode(string $code): array
    {
        return $this->token([
            'grant_type' => 'authorization_code',
            'client_id' => $this->config->clientId(),
            'client_secret' => $this->config->clientSecret(),
            'code' => $code,
            'redirect_uri' => $this->redirectUri(),
        ]);
    }

    /**
     * Swap the refresh token for a fresh access token, and store the refresh token that comes
     * back with it.
     *
     * Atlassian rotates refresh tokens: each use issues a new one and the old is spent. Failing
     * to persist the replacement is how a connection dies quietly an hour later, so the write
     * happens here rather than being left to the caller to remember.
     */
    public function refresh(JiraConnection $connection): JiraConnection
    {
        if ($connection->refresh_token === null) {
            throw new JiraAuthenticationFailedApiException;
        }

        try {
            $tokens = $this->token([
                'grant_type' => 'refresh_token',
                'client_id' => $this->config->clientId(),
                'client_secret' => $this->config->clientSecret(),
                'refresh_token' => $connection->refresh_token,
            ]);
        } catch (JiraAuthenticationFailedApiException $exception) {
            // The refresh token itself is no good - revoked, or unused past its 90 day inactivity
            // window. Flagged so the settings screen can ask for a reconnection rather than every
            // later sync failing with the same opaque error.
            $connection->requires_reauthentication = true;
            $connection->save();

            throw $exception;
        }

        $connection->access_token = $tokens['access_token'];
        // Absent only if offline_access was not granted, in which case the old one is still the
        // best we have - overwriting it with null would end the connection outright.
        $connection->refresh_token = $tokens['refresh_token'] ?? $connection->refresh_token;
        $connection->token_expires_at = Carbon::now()->addSeconds($tokens['expires_in']);
        $connection->requires_reauthentication = false;
        $connection->save();

        return $connection;
    }

    /**
     * A usable access token, refreshed first if it is at or near its expiry.
     */
    public function accessTokenFor(JiraConnection $connection): string
    {
        if ($connection->needsRefresh()) {
            $connection = $this->refresh($connection);
        }

        $token = $connection->access_token;

        if ($token === null) {
            throw new JiraAuthenticationFailedApiException;
        }

        return $token;
    }

    /**
     * The Jira sites this authorisation covers.
     *
     * With a resource-level app that is the single site the person picked on the consent screen,
     * which is what lets the callback check they picked the right one.
     *
     * @return array<int, array{id: string, url: string, name: string}>
     */
    public function accessibleResources(string $accessToken): array
    {
        $response = $this->get(self::RESOURCES_URL, $accessToken);

        $resources = [];
        foreach ($response as $resource) {
            if (is_array($resource) && isset($resource['id'], $resource['url'])) {
                $resources[] = [
                    'id' => (string) $resource['id'],
                    'url' => (string) $resource['url'],
                    'name' => (string) ($resource['name'] ?? $resource['url']),
                ];
            }
        }

        return $resources;
    }

    /**
     * Who the connection belongs to, fetched rather than stored.
     *
     * This is the whole reason the connection row holds no email, account id or display name: the
     * screen that wants them asks Atlassian, shows them, and lets them go. Cached briefly so
     * opening the settings page twice is not two round trips.
     *
     * Returns null rather than throwing - not knowing the name is a cosmetic problem, and it must
     * not be able to break the page that reports whether the connection works.
     *
     * @return array{email: string|null, name: string|null}|null
     */
    public function identity(JiraConnection $connection): ?array
    {
        return Cache::remember(
            'jira-identity:'.$connection->getKey(),
            self::IDENTITY_CACHE_SECONDS,
            function () use ($connection): ?array {
                try {
                    $me = $this->get(self::IDENTITY_URL, $this->accessTokenFor($connection));
                } catch (\Throwable $exception) {
                    Log::debug('Could not read the connected Atlassian account', [
                        'connection_id' => $connection->getKey(),
                        'message' => $exception->getMessage(),
                    ]);

                    return null;
                }

                return [
                    'email' => isset($me['email']) ? (string) $me['email'] : null,
                    'name' => isset($me['name']) ? (string) $me['name'] : null,
                ];
            }
        );
    }

    public function forgetIdentity(JiraConnection $connection): void
    {
        Cache::forget('jira-identity:'.$connection->getKey());
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{access_token: string, refresh_token: string|null, expires_in: int}
     */
    private function token(array $payload): array
    {
        $this->requireConfiguration();

        try {
            $response = Http::asJson()
                ->timeout(self::TIMEOUT_SECONDS)
                ->post(self::TOKEN_URL, $payload);
        } catch (ConnectionException $exception) {
            Log::warning('Could not reach Atlassian for a token', ['message' => $exception->getMessage()]);

            throw new JiraAuthenticationFailedApiException;
        }

        if ($response->failed()) {
            Log::warning('Atlassian refused a token request', [
                'status' => $response->status(),
                // The body carries an error code, never the credential itself.
                'body' => $response->body(),
            ]);

            throw new JiraAuthenticationFailedApiException;
        }

        $data = $response->json();

        if (! is_array($data) || ! isset($data['access_token'])) {
            throw new JiraAuthenticationFailedApiException;
        }

        return [
            'access_token' => (string) $data['access_token'],
            'refresh_token' => isset($data['refresh_token']) ? (string) $data['refresh_token'] : null,
            'expires_in' => (int) ($data['expires_in'] ?? 3600),
        ];
    }

    /**
     * @return array<mixed>
     */
    private function get(string $url, string $accessToken): array
    {
        try {
            $response = Http::withToken($accessToken)
                ->acceptJson()
                ->timeout(self::TIMEOUT_SECONDS)
                ->get($url);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('Could not reach Atlassian: '.$exception->getMessage());
        }

        if ($response->status() === 401) {
            throw new JiraAuthenticationFailedApiException;
        }

        if ($response->failed()) {
            throw new RuntimeException('Atlassian returned '.$response->status());
        }

        $data = $response->json();

        return is_array($data) ? $data : [];
    }

    private function requireConfiguration(): void
    {
        if (! $this->config->isOAuthConfigured()) {
            throw new JiraNotConfiguredApiException;
        }
    }
}
