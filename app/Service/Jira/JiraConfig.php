<?php

declare(strict_types=1);

namespace App\Service\Jira;

use App\Models\Organization;

class JiraConfig
{
    /**
     * The scopes the authorization request asks for.
     *
     * `offline_access` is what yields a refresh token at all, and it is deliberately absent from
     * the developer console's permission list - Atlassian only honours it as a request time
     * scope. `read:me` comes from the User identity API rather than the Jira one, and is narrower
     * than Jira's read:jira-user: the connected account's own profile, nobody else's.
     *
     * @var list<string>
     */
    public const array SCOPES = [
        'read:jira-work',
        'write:jira-work',
        'read:me',
        'offline_access',
    ];

    /**
     * Whether an operator has registered an Atlassian OAuth app.
     *
     * Instance wide, like the Google OAuth client - a self-hosted installation without one never
     * sees the integration offered.
     */
    public function isOAuthConfigured(): bool
    {
        return $this->clientId() !== null && $this->clientSecret() !== null;
    }

    public function clientId(): ?string
    {
        $value = config('services.jira.client_id');

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function clientSecret(): ?string
    {
        $value = config('services.jira.client_secret');

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Whether an admin has pointed the organization at a Jira site. Everything user facing
     * gates on this, so an organization that does not use Jira never sees the integration.
     */
    public function isConfigured(Organization $organization): bool
    {
        return $this->siteUrl($organization) !== null;
    }

    /**
     * The organization's Jira site, normalised to a scheme and host with no trailing slash or
     * path. Returns null if it is missing or not a usable https URL.
     */
    public function siteUrl(Organization $organization): ?string
    {
        return self::normaliseSiteUrl($organization->jira_site_url);
    }

    /**
     * Accepts what someone is likely to paste - "acme.atlassian.net",
     * "https://acme.atlassian.net/", "https://acme.atlassian.net/jira/your-work" - and reduces
     * it to the origin the REST API lives under.
     */
    public static function normaliseSiteUrl(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        // A bare host is by far the most common paste, so assume https rather than rejecting it
        if (! str_contains($value, '://')) {
            $value = 'https://'.$value;
        }

        $parts = parse_url($value);
        if ($parts === false || ! isset($parts['host'])) {
            return null;
        }

        // Tokens travel on every request, so plaintext is not an option
        if (($parts['scheme'] ?? null) !== 'https') {
            return null;
        }

        $host = strtolower($parts['host']);
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return 'https://'.$host.$port;
    }

    /**
     * The project keys issue detection is restricted to, uppercased. An empty list means no
     * restriction, and anything shaped like an issue key is accepted.
     *
     * @return list<string>
     */
    public function projectKeys(Organization $organization): array
    {
        return self::parseProjectKeys($organization->jira_project_keys);
    }

    /**
     * @return list<string>
     */
    public static function parseProjectKeys(?string $value): array
    {
        $keys = [];
        // Tolerates commas, whitespace or both, since this is a free text field
        foreach (preg_split('/[\s,]+/', (string) $value) ?: [] as $key) {
            $key = strtoupper(trim($key));
            if ($key !== '') {
                $keys[] = $key;
            }
        }

        return array_values(array_unique($keys));
    }
}
