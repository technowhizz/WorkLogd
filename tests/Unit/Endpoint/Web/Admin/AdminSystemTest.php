<?php

declare(strict_types=1);

namespace Tests\Unit\Endpoint\Web\Admin;

use App\Http\Controllers\Web\Admin\SystemController;
use App\Models\Audit;
use App\Models\FailedJob;
use App\Models\OrganizationInvitation;
use App\Models\Passport\Client;
use App\Models\Passport\Token;
use App\Models\User;
use Illuminate\Support\Facades\Config;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCaseWithDatabase;

#[CoversClass(SystemController::class)]
class AdminSystemTest extends TestCaseWithDatabase
{
    protected function setUp(): void
    {
        parent::setUp();
        Config::set('auth.super_admins', []);
        $this->actingAs(User::factory()->withPersonalOrganization()->create(['is_admin' => true]));
    }

    public function test_lists_audit_entries(): void
    {
        // Arrange
        Audit::factory()->createMany(3);

        // Act
        $response = $this->get(route('admin.audits.index'));

        // Assert
        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Audits')
            ->has('audits.data')
            ->has('eventOptions')
        );
    }

    public function test_can_filter_audits_by_event(): void
    {
        // Arrange
        Audit::factory()->create(['event' => 'created']);
        Audit::factory()->create(['event' => 'deleted']);

        // Act
        $response = $this->get(route('admin.audits.index', ['event' => 'deleted']));

        // Assert
        $response->assertInertia(fn (Assert $page) => $page
            ->has('audits.data', 1)
            ->where('audits.data.0.event', 'deleted')
        );
    }

    public function test_lists_failed_jobs_and_splits_off_the_first_line_of_the_exception(): void
    {
        // Arrange
        FailedJob::factory()->create([
            'exception' => "RuntimeException: it broke\n#0 /app/foo.php(1)\n#1 /app/bar.php(2)",
        ]);

        // Act
        $response = $this->get(route('admin.failed-jobs.index'));

        // Assert
        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Admin/FailedJobs')
            ->has('jobs.data', 1)
            ->where('jobs.data.0.summary', 'RuntimeException: it broke')
        );
    }

    public function test_can_delete_a_failed_job(): void
    {
        // Arrange
        $job = FailedJob::factory()->create();

        // Act
        $response = $this->delete(route('admin.failed-jobs.destroy', $job->uuid));

        // Assert
        $response->assertRedirect();
        $this->assertDatabaseMissing('failed_jobs', ['uuid' => $job->uuid]);
    }

    public function test_lists_api_tokens(): void
    {
        // Arrange
        Token::factory()->forClient(Client::factory()->create())->createMany(2);

        // Act
        $response = $this->get(route('admin.tokens.index'));

        // Assert
        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Tokens')
            ->has('tokens.data', 2)
        );
    }

    public function test_can_revoke_a_token(): void
    {
        // Arrange
        $token = Token::factory()->forClient(Client::factory()->create())->create(['revoked' => false]);

        // Act
        $response = $this->delete(route('admin.tokens.revoke', $token->getKey()));

        // Assert
        $response->assertRedirect();
        $token->refresh();
        $this->assertTrue($token->revoked);
    }

    public function test_can_filter_tokens_to_the_revoked_ones(): void
    {
        // Arrange
        $client = Client::factory()->create();
        Token::factory()->forClient($client)->create(['revoked' => true]);
        Token::factory()->forClient($client)->create(['revoked' => false]);

        // Act
        $response = $this->get(route('admin.tokens.index', ['state' => 'revoked']));

        // Assert
        $response->assertInertia(fn (Assert $page) => $page->has('tokens.data', 1));
    }

    public function test_lists_invitations(): void
    {
        // Arrange
        OrganizationInvitation::factory()->createMany(2);

        // Act
        $response = $this->get(route('admin.invitations.index'));

        // Assert
        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Invitations')
            ->has('invitations.data', 2)
        );
    }

    public function test_can_filter_invitations_to_the_pending_ones(): void
    {
        // Arrange
        OrganizationInvitation::factory()->create();
        OrganizationInvitation::factory()->accepted()->create();

        // Act
        $response = $this->get(route('admin.invitations.index', ['state' => 'pending']));

        // Assert
        $response->assertInertia(fn (Assert $page) => $page->has('invitations.data', 1));
    }

    public function test_can_withdraw_an_invitation(): void
    {
        // Arrange
        $invitation = OrganizationInvitation::factory()->create();

        // Act
        $response = $this->delete(route('admin.invitations.destroy', $invitation->getKey()));

        // Assert
        $response->assertRedirect();
        $this->assertDatabaseMissing('organization_invitations', ['id' => $invitation->getKey()]);
    }
}
