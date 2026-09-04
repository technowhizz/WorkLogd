<?php

declare(strict_types=1);

namespace Tests\Unit\Endpoint\Web;

use App\Http\Controllers\Web\JiraConnectionController;
use App\Models\JiraConnection;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCaseWithDatabase;

#[CoversClass(JiraConnectionController::class)]
class JiraConnectionEndpointTest extends TestCaseWithDatabase
{
    private const TOKEN_URL = 'https://auth.atlassian.com/oauth/token';

    private const RESOURCES_URL = 'https://api.atlassian.com/oauth/token/accessible-resources';

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('services.jira.client_id', 'client-id');
        Config::set('services.jira.client_secret', 'client-secret');
    }

    /**
     * @return object{user: User, organization: Organization}
     */
    private function memberOfJiraOrganization(?string $siteUrl = 'https://acme.atlassian.net'): object
    {
        $data = $this->createUserWithPermission([], isOwner: true);
        $data->organization->jira_site_url = $siteUrl;
        $data->organization->save();
        $data->user->currentOrganization()->associate($data->organization);
        $data->user->save();

        return $data;
    }

    private function fakeSuccessfulExchange(string $siteUrl = 'https://acme.atlassian.net'): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response([
                'access_token' => 'access-1',
                'refresh_token' => 'refresh-1',
                'expires_in' => 3600,
            ], 200),
            self::RESOURCES_URL => Http::response([
                ['id' => 'cloud-1', 'url' => $siteUrl, 'name' => 'Acme'],
            ], 200),
        ]);
    }

    public function test_connect_sends_the_user_to_atlassian(): void
    {
        // Arrange
        $data = $this->memberOfJiraOrganization();
        $this->actingAs($data->user);

        // Act
        $response = $this->get(route('integrations.jira.connect'));

        // Assert
        $response->assertRedirectContains('auth.atlassian.com/authorize');
        $response->assertRedirectContains('offline_access');
        $this->assertNotNull(session('jira_oauth_state'));
    }

    public function test_connect_refuses_when_the_organization_has_no_jira_site(): void
    {
        // Arrange
        $data = $this->memberOfJiraOrganization(null);
        $this->actingAs($data->user);

        // Act
        $response = $this->get(route('integrations.jira.connect'));

        // Assert
        $response->assertRedirect(route('profile.show'));
        $response->assertSessionHas('bannerStyle', 'danger');
    }

    public function test_connect_refuses_when_the_instance_has_no_oauth_app(): void
    {
        // Arrange
        Config::set('services.jira.client_id', null);
        $data = $this->memberOfJiraOrganization();
        $this->actingAs($data->user);

        // Act
        $response = $this->get(route('integrations.jira.connect'));

        // Assert
        $response->assertSessionHas('bannerStyle', 'danger');
    }

    public function test_the_callback_stores_the_tokens_and_the_cloud_id(): void
    {
        // Arrange
        $data = $this->memberOfJiraOrganization();
        $this->actingAs($data->user);
        $this->fakeSuccessfulExchange();

        // Act
        $response = $this->withSession([
            'jira_oauth_state' => 'the-state',
            'jira_oauth_organization' => $data->organization->getKey(),
        ])->get(route('integrations.jira.callback', ['code' => 'the-code', 'state' => 'the-state']));

        // Assert
        $response->assertRedirect(route('profile.show'));
        $response->assertSessionHas('bannerStyle', 'success');
        $connection = JiraConnection::query()->where('user_id', '=', $data->user->getKey())->first();
        $this->assertNotNull($connection);
        $this->assertSame('refresh-1', $connection->refresh_token);
        $this->assertSame('cloud-1', $connection->cloud_id);
        $this->assertFalse($connection->requires_reauthentication);
    }

    public function test_the_callback_refuses_a_site_that_is_not_the_organizations(): void
    {
        // Arrange
        $data = $this->memberOfJiraOrganization('https://acme.atlassian.net');
        $this->actingAs($data->user);
        // The consent screen lets people pick any site they administer, including the wrong one.
        $this->fakeSuccessfulExchange('https://someone-else.atlassian.net');

        // Act
        $response = $this->withSession([
            'jira_oauth_state' => 'the-state',
            'jira_oauth_organization' => $data->organization->getKey(),
        ])->get(route('integrations.jira.callback', ['code' => 'the-code', 'state' => 'the-state']));

        // Assert
        $response->assertSessionHas('bannerStyle', 'danger');
        // Nothing stored, so weeks of time cannot end up logged in the wrong Jira.
        $this->assertSame(0, JiraConnection::query()->count());
    }

    public function test_the_callback_rejects_a_state_that_does_not_match(): void
    {
        // Arrange
        $data = $this->memberOfJiraOrganization();
        $this->actingAs($data->user);
        Http::preventStrayRequests();

        // Act
        $response = $this->withSession([
            'jira_oauth_state' => 'the-real-state',
            'jira_oauth_organization' => $data->organization->getKey(),
        ])->get(route('integrations.jira.callback', ['code' => 'c', 'state' => 'a-forgery']));

        // Assert
        $response->assertSessionHas('bannerStyle', 'danger');
        $this->assertSame(0, JiraConnection::query()->count());
        Http::assertNothingSent();
    }

    public function test_the_callback_handles_a_declined_consent_screen(): void
    {
        // Arrange
        $data = $this->memberOfJiraOrganization();
        $this->actingAs($data->user);
        Http::preventStrayRequests();

        // Act
        $response = $this->withSession(['jira_oauth_state' => 'the-state'])
            ->get(route('integrations.jira.callback', ['error' => 'access_denied', 'state' => 'the-state']));

        // Assert
        $response->assertRedirect(route('profile.show'));
        $this->assertSame(0, JiraConnection::query()->count());
    }

    public function test_the_callback_refuses_an_organization_the_user_does_not_belong_to(): void
    {
        // Arrange
        $data = $this->memberOfJiraOrganization();
        $this->actingAs($data->user);
        $other = Organization::factory()->create(['jira_site_url' => 'https://acme.atlassian.net']);
        Http::preventStrayRequests();

        // Act
        $response = $this->withSession([
            'jira_oauth_state' => 'the-state',
            'jira_oauth_organization' => $other->getKey(),
        ])->get(route('integrations.jira.callback', ['code' => 'c', 'state' => 'the-state']));

        // Assert
        $response->assertSessionHas('bannerStyle', 'danger');
        $this->assertSame(0, JiraConnection::query()->count());
    }

    public function test_reconnecting_replaces_the_existing_tokens(): void
    {
        // Arrange
        $data = $this->memberOfJiraOrganization();
        $existing = JiraConnection::factory()
            ->forUser($data->user)
            ->forOrganization($data->organization)
            ->requiresReauthentication()
            ->create(['refresh_token' => 'stale-refresh']);
        $this->actingAs($data->user);
        $this->fakeSuccessfulExchange();

        // Act
        $this->withSession([
            'jira_oauth_state' => 'the-state',
            'jira_oauth_organization' => $data->organization->getKey(),
        ])->get(route('integrations.jira.callback', ['code' => 'the-code', 'state' => 'the-state']));

        // Assert
        $this->assertSame(1, JiraConnection::query()->count());
        $existing->refresh();
        $this->assertSame('refresh-1', $existing->refresh_token);
        $this->assertFalse($existing->requires_reauthentication);
    }

    public function test_a_guest_cannot_start_the_flow(): void
    {
        // Act
        $response = $this->get(route('integrations.jira.connect'));

        // Assert
        $response->assertRedirect(route('login'));
    }
}
