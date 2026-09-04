<?php

declare(strict_types=1);

namespace App\Service\Jira;

use App\Exceptions\Api\JiraAuthenticationFailedApiException;
use App\Exceptions\Api\JiraNotConfiguredApiException;
use App\Exceptions\Api\JiraRequestFailedApiException;
use App\Models\JiraConnection;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;

/**
 * An in-process stand-in for a Jira Cloud site, so the browser suite can exercise the whole
 * integration - connecting an account, previewing, syncing, and the resulting indicators -
 * without an Atlassian account and without one outbound request.
 *
 * Three things about it are deliberate.
 *
 * **It cannot be switched on in production.** isEnabled() demands both `services.jira.fake`
 * (which is only ever read from env() inside config/services.php) and an environment that is not
 * production. Either one alone is not enough, and the binding in AppServiceProvider asks this
 * class rather than reading the flag itself, so there is exactly one gate to audit.
 * FakeJiraClientBindingTest pins both halves.
 *
 * **Its state lives in the cache**, keyed per organization. Playwright drives a real, long lived
 * server, so a worklog created by one request has to still be there for the next one - a fake
 * that only lived for the duration of a request could not tell an update of an existing worklog
 * from a create, which is precisely the behaviour worth testing. The cache was chosen over a
 * table because a table means a migration, a `down()`, a phpstan model docblock and an entry in
 * DeletionService shipped to production for something production must never run; and over a file
 * because JiraSyncRunStore already keeps this exact kind of short lived, per user run state in
 * the cache. Under PHPUnit the cache driver is `array`, so every test starts from an empty Jira
 * for free.
 *
 * **It fails where the real one fails.** A token starting with `invalid` is rejected the way a
 * bad credential is, an issue in the `FAIL` project is rejected the way a typo'd key is, and
 * updating a worklog id the site has never issued 404s. Without those a green browser suite
 * would only prove the happy path renders.
 */
class FakeJiraClient implements JiraClientContract
{
    /** Long enough for any browser run, short enough that nothing lingers for a day. */
    private const int TTL_SECONDS = 6 * 3600;

    /** A token starting with this is treated as a bad credential. */
    public const string REJECTED_TOKEN_PREFIX = 'invalid';

    /** Worklogs against this project key fail the way an issue that does not exist does. */
    public const string FAILING_PROJECT_KEY = 'FAIL';

    public function __construct(private readonly JiraConfig $config) {}

    /**
     * The single gate. Both halves are required: a stray `JIRA_FAKE_CLIENT=true` in a production
     * deployment still resolves the real client, and so does running non-production without the
     * flag. The binding in AppServiceProvider is a plain bind(), so this is re-evaluated on every
     * resolution rather than frozen at boot.
     */
    public static function isEnabled(): bool
    {
        return config('services.jira.fake') === true && ! app()->environment('production');
    }

    public function createWorklog(JiraConnection $connection, string $issueKey, ?string $comment, CarbonInterface $startedAt, int $durationSeconds): string
    {
        $this->requireSite($connection);
        $this->requireCredentials($connection);
        $this->requireLoggableIssue($issueKey);

        $site = $this->site($connection);
        $worklogs = $this->worklogs($site);
        $worklogId = 'fake-worklog-'.(count($worklogs) + 1).'-'.substr(md5($issueKey.$startedAt->format('c').$durationSeconds), 0, 6);

        $worklogs[$worklogId] = [
            'issue_key' => $issueKey,
            'comment' => $comment,
            // Stored in Jira's own wire format, so a wrong offset would be visible here
            'started' => $startedAt->format(JiraClient::STARTED_FORMAT),
            'time_spent_seconds' => $durationSeconds,
        ];

        $this->put($site, $worklogs);

        return $worklogId;
    }

    public function updateWorklog(JiraConnection $connection, string $issueKey, string $worklogId, ?string $comment, CarbonInterface $startedAt, int $durationSeconds): void
    {
        $this->requireSite($connection);
        $this->requireCredentials($connection);
        $this->requireLoggableIssue($issueKey);

        $site = $this->site($connection);
        $worklogs = $this->worklogs($site);

        // Strict on purpose: this is what proves the fake really is stateful across requests.
        // The worklog being updated was created by an earlier HTTP request entirely.
        if (! isset($worklogs[$worklogId])) {
            throw JiraRequestFailedApiException::withDetail('Worklog does not exist or you do not have permission to see it.');
        }

        $worklogs[$worklogId] = [
            'issue_key' => $issueKey,
            'comment' => $comment,
            'started' => $startedAt->format(JiraClient::STARTED_FORMAT),
            'time_spent_seconds' => $durationSeconds,
        ];

        $this->put($site, $worklogs);
    }

    public function deleteWorklog(JiraConnection $connection, string $issueKey, string $worklogId): void
    {
        $this->requireSite($connection);
        $this->requireCredentials($connection);

        $site = $this->site($connection);
        $worklogs = $this->worklogs($site);
        // Tolerant where update is strict: deleting something already gone is the intended end
        // state either way, and a cache that has been cleared under a running suite should not
        // turn a passing delete into a failure.
        unset($worklogs[$worklogId]);

        $this->put($site, $worklogs);
    }

    /**
     * Everything the fake site currently holds, for assertions.
     *
     * @return array<string, array{issue_key: string, comment: string|null, started: string, time_spent_seconds: int}>
     */
    public function worklogsFor(JiraConnection $connection): array
    {
        return $this->worklogs($this->site($connection));
    }

    /**
     * @return array<string, array{issue_key: string, comment: string|null, started: string, time_spent_seconds: int}>
     */
    private function worklogs(string $site): array
    {
        $stored = Cache::get($this->key($site));

        /** @var array<string, array{issue_key: string, comment: string|null, started: string, time_spent_seconds: int}> */
        return is_array($stored) ? $stored : [];
    }

    /**
     * @param  array<string, array{issue_key: string, comment: string|null, started: string, time_spent_seconds: int}>  $worklogs
     */
    private function put(string $site, array $worklogs): void
    {
        Cache::put($this->key($site), $worklogs, self::TTL_SECONDS);
    }

    private function key(string $site): string
    {
        return 'jira-fake-site:'.$site;
    }

    /**
     * Namespaced by organization rather than by site URL, so two organizations pointed at the
     * same Jira still get independent fakes and one test cannot see another's worklogs.
     */
    private function site(JiraConnection $connection): string
    {
        return $connection->organization_id;
    }

    /** Mirrors JiraClient: an organization with no site cannot be talked to at all. */
    private function requireSite(JiraConnection $connection): void
    {
        $organization = $connection->organization()->first();
        if ($organization === null || $this->config->siteUrl($organization) === null) {
            throw new JiraNotConfiguredApiException;
        }
    }

    private function requireCredentials(JiraConnection $connection): void
    {
        $token = $connection->access_token;

        if ($token === null || $token === '' || $connection->cloud_id === null) {
            throw new JiraAuthenticationFailedApiException;
        }

        // The browser suite proves the reauthentication path by connecting a token that starts
        // with this prefix, so the rejection has to survive the move to OAuth.
        if (str_starts_with(mb_strtolower($token), self::REJECTED_TOKEN_PREFIX)) {
            throw new JiraAuthenticationFailedApiException;
        }
    }

    private function requireLoggableIssue(string $issueKey): void
    {
        if (str_starts_with(strtoupper($issueKey), self::FAILING_PROJECT_KEY.'-')) {
            throw JiraRequestFailedApiException::withDetail('Issue does not exist or you do not have permission to see it.');
        }
    }
}
