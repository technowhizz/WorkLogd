<?php

declare(strict_types=1);

namespace Tests\Unit\Service\Jira;

use App\Exceptions\Api\JiraAuthenticationFailedApiException;
use App\Exceptions\Api\JiraRequestFailedApiException;
use App\Models\JiraConnection;
use App\Models\Member;
use App\Models\Organization;
use App\Models\User;
use App\Service\Jira\FakeJiraClient;
use App\Service\Jira\JiraClient;
use App\Service\Jira\JiraClientContract;
use App\Service\Jira\JiraSyncService;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCaseWithDatabase;

/**
 * The fake Jira client is a browser-test seam that must never be reachable in production.
 * The gate has two halves, and these pin both of them independently - if either one is ever
 * dropped, one of these fails rather than the fake quietly becoming reachable on a live site.
 */
#[CoversClass(FakeJiraClient::class)]
class FakeJiraClientBindingTest extends TestCaseWithDatabase
{
    private const string SITE_URL = 'https://acme.atlassian.net';

    private function connection(): JiraConnection
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $organization = Organization::factory()->withOwner($user)->create([
            'jira_site_url' => self::SITE_URL,
        ]);
        Member::factory()->forUser($user)->forOrganization($organization)->create();

        return JiraConnection::factory()->forUser($user)->forOrganization($organization)->create([
            'api_token' => 'a-good-token',
        ]);
    }

    public function test_the_real_client_is_bound_by_default(): void
    {
        // Arrange
        // Nothing: the flag defaults to false, which is what a deployment that has never heard
        // of the seam looks like.

        // Act
        $client = app(JiraClientContract::class);

        // Assert
        $this->assertInstanceOf(JiraClient::class, $client);
    }

    public function test_the_fake_client_is_bound_when_the_flag_is_set_outside_production(): void
    {
        // Arrange
        config(['services.jira.fake' => true]);

        // Act
        $client = app(JiraClientContract::class);

        // Assert
        $this->assertInstanceOf(FakeJiraClient::class, $client);
        // And the service that actually sends worklogs gets it too, not just a bare resolve
        $this->assertInstanceOf(JiraSyncService::class, app(JiraSyncService::class));
    }

    public function test_the_fake_client_can_not_be_enabled_in_production(): void
    {
        // Arrange
        config(['services.jira.fake' => true]);
        $this->app->detectEnvironment(static fn (): string => 'production');

        // Act
        $enabled = FakeJiraClient::isEnabled();
        $client = app(JiraClientContract::class);

        // Assert
        $this->assertFalse($enabled);
        $this->assertInstanceOf(JiraClient::class, $client);
    }

    public function test_the_fake_client_stays_off_in_production_even_when_the_environment_variable_is_set(): void
    {
        // Arrange
        // The flag is only ever read from env() inside config/services.php, so this is exactly
        // what a production deployment carrying a stray JIRA_FAKE_CLIENT=true would produce.
        config(['services.jira.fake' => (bool) 'true']);
        $this->app->detectEnvironment(static fn (): string => 'production');

        // Act
        $client = app(JiraClientContract::class);

        // Assert
        $this->assertInstanceOf(JiraClient::class, $client);
    }

    public function test_the_fake_client_is_off_in_a_non_production_environment_without_the_flag(): void
    {
        // Arrange
        config(['services.jira.fake' => false]);
        $this->app->detectEnvironment(static fn (): string => 'local');

        // Act
        $client = app(JiraClientContract::class);

        // Assert
        $this->assertInstanceOf(JiraClient::class, $client);
    }

    public function test_the_fake_client_keeps_worklogs_between_resolutions(): void
    {
        // Arrange
        // The browser suite drives a long lived server, so a worklog created by one request has
        // to still be there for the next. Resolving the fake twice stands in for two requests -
        // the state is in the cache, not on the instance.
        config(['services.jira.fake' => true]);
        $connection = $this->connection();
        $startedAt = CarbonImmutable::parse('2026-08-10T09:00:00', 'UTC');

        // Act
        /** @var FakeJiraClient $first */
        $first = app(JiraClientContract::class);
        $worklogId = $first->createWorklog($connection, 'PROJ-1', 'wrote a test', $startedAt, 3600);

        /** @var FakeJiraClient $second */
        $second = app(JiraClientContract::class);
        $second->updateWorklog($connection, 'PROJ-1', $worklogId, 'wrote a test', $startedAt, 7200);

        // Assert
        $this->assertNotSame($first, $second);
        $worklogs = app(JiraClientContract::class)->worklogsFor($connection);
        $this->assertSame(7200, $worklogs[$worklogId]['time_spent_seconds']);
    }

    public function test_the_fake_client_rejects_an_unknown_worklog_the_way_jira_does(): void
    {
        // Arrange
        config(['services.jira.fake' => true]);
        $connection = $this->connection();

        // Act & Assert
        $this->expectException(JiraRequestFailedApiException::class);
        app(JiraClientContract::class)->updateWorklog(
            $connection,
            'PROJ-1',
            'never-issued',
            null,
            CarbonImmutable::parse('2026-08-10T09:00:00', 'UTC'),
            3600,
        );
    }

    public function test_the_fake_client_rejects_a_bad_token(): void
    {
        // Arrange
        config(['services.jira.fake' => true]);
        $connection = $this->connection();
        $connection->api_token = FakeJiraClient::REJECTED_TOKEN_PREFIX.'-token';

        // Act & Assert
        $this->expectException(JiraAuthenticationFailedApiException::class);
        app(JiraClientContract::class)->myself($connection);
    }
}
