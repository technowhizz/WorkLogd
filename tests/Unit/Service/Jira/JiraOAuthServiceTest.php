<?php

declare(strict_types=1);

namespace Tests\Unit\Service\Jira;

use App\Exceptions\Api\JiraAuthenticationFailedApiException;
use App\Exceptions\Api\JiraNotConfiguredApiException;
use App\Models\JiraConnection;
use App\Service\Jira\JiraOAuthService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCaseWithDatabase;

#[CoversClass(JiraOAuthService::class)]
class JiraOAuthServiceTest extends TestCaseWithDatabase
{
    private const TOKEN_URL = 'https://auth.atlassian.com/oauth/token';

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('services.jira.client_id', 'client-id');
        Config::set('services.jira.client_secret', 'client-secret');
        Config::set('app.url', 'https://worklogd.test');
    }

    private function service(): JiraOAuthService
    {
        return app(JiraOAuthService::class);
    }

    public function test_the_authorization_url_asks_for_offline_access(): void
    {
        // Act
        $url = $this->service()->authorizationUrl('the-state');

        // Assert
        // Without offline_access Atlassian returns no refresh token at all, and the connection
        // would die an hour later. It is deliberately absent from the developer console, so this
        // is the only place it can be got wrong.
        $this->assertStringContainsString('offline_access', urldecode($url));
        $this->assertStringContainsString('read%3Ajira-work', $url);
        $this->assertStringContainsString('write%3Ajira-work', $url);
        $this->assertStringContainsString('read%3Ame', $url);
    }

    public function test_the_authorization_url_forces_the_consent_screen(): void
    {
        // Act
        $url = $this->service()->authorizationUrl('the-state');

        // Assert
        // Without it, a repeat authorisation returns no refresh token.
        $this->assertStringContainsString('prompt=consent', $url);
        $this->assertStringContainsString('audience=api.atlassian.com', urldecode($url));
        $this->assertStringContainsString('state=the-state', $url);
    }

    public function test_it_refuses_to_build_a_url_without_an_oauth_app(): void
    {
        // Arrange
        Config::set('services.jira.client_id', null);

        // Act & Assert
        $this->expectException(JiraNotConfiguredApiException::class);
        $this->service()->authorizationUrl('the-state');
    }

    public function test_the_redirect_uri_is_resolved_against_the_app_url(): void
    {
        // Act & Assert
        $this->assertSame(
            'https://worklogd.test/integrations/jira/callback',
            $this->service()->redirectUri()
        );
    }

    public function test_exchanging_a_code_returns_both_tokens(): void
    {
        // Arrange
        Http::fake([self::TOKEN_URL => Http::response([
            'access_token' => 'access-1',
            'refresh_token' => 'refresh-1',
            'expires_in' => 3600,
        ], 200)]);

        // Act
        $tokens = $this->service()->exchangeCode('the-code');

        // Assert
        $this->assertSame('access-1', $tokens['access_token']);
        $this->assertSame('refresh-1', $tokens['refresh_token']);
        $this->assertSame(3600, $tokens['expires_in']);
    }

    public function test_refreshing_stores_the_rotated_refresh_token(): void
    {
        // Arrange
        $connection = JiraConnection::factory()->create(['refresh_token' => 'old-refresh']);
        Http::fake([self::TOKEN_URL => Http::response([
            'access_token' => 'access-2',
            'refresh_token' => 'new-refresh',
            'expires_in' => 3600,
        ], 200)]);

        // Act
        $this->service()->refresh($connection);

        // Assert
        // Atlassian rotates refresh tokens: the old one is spent the moment it is used. Keeping
        // it would mean the next refresh fails and the connection dies quietly an hour later.
        $this->assertSame('new-refresh', $connection->fresh()?->refresh_token);
        $this->assertSame('access-2', $connection->fresh()?->access_token);
    }

    public function test_refreshing_keeps_the_old_token_when_none_comes_back(): void
    {
        // Arrange
        $connection = JiraConnection::factory()->create(['refresh_token' => 'old-refresh']);
        Http::fake([self::TOKEN_URL => Http::response([
            'access_token' => 'access-2',
            'expires_in' => 3600,
        ], 200)]);

        // Act
        $this->service()->refresh($connection);

        // Assert
        // Overwriting with null would end the connection outright for no reason.
        $this->assertSame('old-refresh', $connection->fresh()?->refresh_token);
    }

    public function test_a_rejected_refresh_flags_the_connection_for_reconnection(): void
    {
        // Arrange
        $connection = JiraConnection::factory()->create(['refresh_token' => 'expired-refresh']);
        Http::fake([self::TOKEN_URL => Http::response(['error' => 'invalid_grant'], 400)]);

        // Act
        try {
            $this->service()->refresh($connection);
            $this->fail('Expected the refresh to be refused.');
        } catch (JiraAuthenticationFailedApiException) {
            // expected
        }

        // Assert
        // A refresh token unused past its 90 day inactivity window lands here. Flagged so the
        // settings screen asks for a reconnection instead of every later sync failing opaquely.
        $this->assertTrue($connection->fresh()?->requires_reauthentication);
    }

    public function test_a_token_near_its_expiry_is_refreshed_before_use(): void
    {
        // Arrange
        $connection = JiraConnection::factory()->expired()->create(['refresh_token' => 'r']);
        Http::fake([self::TOKEN_URL => Http::response([
            'access_token' => 'fresh-access',
            'refresh_token' => 'r2',
            'expires_in' => 3600,
        ], 200)]);

        // Act
        $token = $this->service()->accessTokenFor($connection);

        // Assert
        $this->assertSame('fresh-access', $token);
    }

    public function test_a_live_token_is_used_without_a_round_trip(): void
    {
        // Arrange
        Http::preventStrayRequests();
        $connection = JiraConnection::factory()->create([
            'access_token' => 'still-good',
            'token_expires_at' => Carbon::now()->addHour(),
        ]);

        // Act
        $token = $this->service()->accessTokenFor($connection);

        // Assert
        $this->assertSame('still-good', $token);
        Http::assertNothingSent();
    }

    public function test_accessible_resources_are_read_from_the_authorisation(): void
    {
        // Arrange
        Http::fake([
            'https://api.atlassian.com/oauth/token/accessible-resources' => Http::response([
                ['id' => 'cloud-1', 'url' => 'https://acme.atlassian.net', 'name' => 'Acme'],
            ], 200),
        ]);

        // Act
        $resources = $this->service()->accessibleResources('access-token');

        // Assert
        $this->assertCount(1, $resources);
        $this->assertSame('cloud-1', $resources[0]['id']);
        $this->assertSame('https://acme.atlassian.net', $resources[0]['url']);
    }

    public function test_the_identity_is_fetched_rather_than_stored(): void
    {
        // Arrange
        $connection = JiraConnection::factory()->create();
        Http::fake([
            'https://api.atlassian.com/me' => Http::response([
                'email' => 'someone@acme.test',
                'name' => 'Someone',
            ], 200),
        ]);

        // Act
        $identity = $this->service()->identity($connection);

        // Assert
        $this->assertSame('someone@acme.test', $identity['email'] ?? null);
        $this->assertSame('Someone', $identity['name'] ?? null);
        // The whole point: none of it lands in the table, which is what keeps the app outside
        // the Personal Data Reporting API's scope.
        $this->assertArrayNotHasKey('email', $connection->fresh()?->getAttributes() ?? []);
        $this->assertArrayNotHasKey('account_id', $connection->fresh()?->getAttributes() ?? []);
        $this->assertArrayNotHasKey('display_name', $connection->fresh()?->getAttributes() ?? []);
    }

    public function test_an_unreachable_identity_is_not_fatal(): void
    {
        // Arrange
        $connection = JiraConnection::factory()->create();
        Http::fake(['https://api.atlassian.com/me' => Http::response([], 500)]);

        // Act
        $identity = $this->service()->identity($connection);

        // Assert
        // Not knowing the name is cosmetic; it must not stop the page reporting whether the
        // connection itself works.
        $this->assertNull($identity);
    }
}
