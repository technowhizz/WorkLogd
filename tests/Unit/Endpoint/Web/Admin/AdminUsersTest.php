<?php

declare(strict_types=1);

namespace Tests\Unit\Endpoint\Web\Admin;

use App\Exceptions\Api\CanNotDeleteUserWhoIsOwnerOfOrganizationWithMultipleMembers;
use App\Http\Controllers\Web\Admin\UserController;
use App\Models\User;
use App\Service\DeletionService;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCaseWithDatabase;

#[CoversClass(UserController::class)]
class AdminUsersTest extends TestCaseWithDatabase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('auth.super_admins', []);
        $this->admin = User::factory()->withPersonalOrganization()->create([
            'name' => 'The Admin',
            'is_admin' => true,
        ]);
        $this->actingAs($this->admin);
    }

    public function test_lists_users(): void
    {
        // Arrange
        User::factory()->createMany(3);

        // Act
        $response = $this->get(route('admin.users.index'));

        // Assert
        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Users')
            ->has('users.data', 4)
        );
    }

    public function test_can_search_users_by_email(): void
    {
        // Arrange
        User::factory()->create(['email' => 'findable@example.test']);
        User::factory()->create(['email' => 'hidden@example.test']);

        // Act
        $response = $this->get(route('admin.users.index', ['search' => 'findable']));

        // Assert
        $response->assertInertia(fn (Assert $page) => $page
            ->has('users.data', 1)
            ->where('users.data.0.email', 'findable@example.test')
        );
    }

    public function test_can_filter_to_super_admins(): void
    {
        // Arrange
        User::factory()->createMany(3);

        // Act
        $response = $this->get(route('admin.users.index', ['type' => 'admin']));

        // Assert
        $response->assertInertia(fn (Assert $page) => $page
            ->has('users.data', 1)
            ->where('users.data.0.name', 'The Admin')
        );
    }

    public function test_shows_a_user_with_their_memberships(): void
    {
        // Arrange
        $data = $this->createUserWithPermission(isOwner: true);

        // Act
        $response = $this->get(route('admin.users.show', $data->user->getKey()));

        // Assert
        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Admin/UserShow')
            ->where('user.id', $data->user->getKey())
            ->where('user.is_self', false)
            ->has('organizations', 1)
        );
    }

    public function test_can_grant_admin_access_to_another_user(): void
    {
        // Arrange
        $user = User::factory()->create(['is_admin' => false]);

        // Act
        $this->put(route('admin.users.update', $user->getKey()), $this->payload($user, ['is_admin' => true]));

        // Assert
        $user->refresh();
        $this->assertTrue($user->is_admin);
    }

    public function test_can_not_revoke_your_own_admin_access(): void
    {
        // Act
        $this->put(
            route('admin.users.update', $this->admin->getKey()),
            $this->payload($this->admin, ['is_admin' => false])
        );

        // Assert
        $this->admin->refresh();
        $this->assertTrue($this->admin->is_admin);
    }

    public function test_can_not_revoke_admin_access_that_the_environment_grants(): void
    {
        // Arrange
        Config::set('auth.super_admins', ['configured@example.test']);
        $user = User::factory()->create(['email' => 'configured@example.test', 'is_admin' => true]);

        // Act
        $this->put(route('admin.users.update', $user->getKey()), $this->payload($user, ['is_admin' => false]));

        // Assert
        $user->refresh();
        $this->assertTrue($user->is_admin);
        $this->assertTrue($user->isSuperAdmin());
    }

    public function test_can_set_a_new_password(): void
    {
        // Arrange
        $user = User::factory()->create();

        // Act
        $this->put(route('admin.users.update', $user->getKey()), $this->payload($user, [
            'password' => 'a-brand-new-password',
        ]));

        // Assert
        $user->refresh();
        $this->assertTrue(Hash::check('a-brand-new-password', $user->password));
    }

    public function test_refuses_an_email_another_account_already_uses(): void
    {
        // Arrange
        User::factory()->create(['email' => 'taken@example.test']);
        $user = User::factory()->create(['email' => 'mine@example.test']);

        // Act
        $response = $this->put(
            route('admin.users.update', $user->getKey()),
            $this->payload($user, ['email' => 'taken@example.test'])
        );

        // Assert
        $response->assertSessionHas('bannerStyle', 'danger');
        $user->refresh();
        $this->assertSame('mine@example.test', $user->email);
    }

    public function test_deleting_a_user_goes_through_the_deletion_service(): void
    {
        // Arrange
        $user = User::factory()->create();
        $this->mock(DeletionService::class, function (MockInterface $mock) use ($user): void {
            $mock->shouldReceive('deleteUser')
                ->once()
                ->withArgs(fn (User $argument): bool => $argument->is($user));
        });

        // Act
        $response = $this->delete(route('admin.users.destroy', $user->getKey()));

        // Assert
        $response->assertRedirect(route('admin.users.index'));
    }

    public function test_can_not_delete_yourself(): void
    {
        // Act
        $response = $this->delete(route('admin.users.destroy', $this->admin->getKey()));

        // Assert
        $response->assertSessionHas('bannerStyle', 'danger');
        $this->assertDatabaseHas('users', ['id' => $this->admin->getKey()]);
    }

    public function test_reports_a_delete_the_service_refuses(): void
    {
        // Arrange
        $user = User::factory()->create();
        $this->mock(DeletionService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('deleteUser')
                ->once()
                ->andThrow(new CanNotDeleteUserWhoIsOwnerOfOrganizationWithMultipleMembers);
        });

        // Act
        $response = $this->delete(route('admin.users.destroy', $user->getKey()));

        // Assert
        $response->assertSessionHas('bannerStyle', 'danger');
    }

    public function test_can_resend_a_verification_email(): void
    {
        // Arrange
        Notification::fake();
        $user = User::factory()->unverified()->create();

        // Act
        $response = $this->post(route('admin.users.resend-verification', $user->getKey()));

        // Assert
        $response->assertSessionHas('bannerStyle', 'success');
        Notification::assertSentTo($user, VerifyEmail::class);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(User $user, array $overrides = []): array
    {
        return array_merge([
            'name' => $user->name,
            'email' => $user->email,
            'timezone' => $user->timezone,
            'week_start' => $user->week_start->value,
            'is_admin' => $user->is_admin,
            'is_email_verified' => $user->email_verified_at !== null,
            'password' => null,
        ], $overrides);
    }
}
