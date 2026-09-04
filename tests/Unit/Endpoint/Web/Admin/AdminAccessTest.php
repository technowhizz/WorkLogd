<?php

declare(strict_types=1);

namespace Tests\Unit\Endpoint\Web\Admin;

use App\Http\Middleware\EnsureUserIsAdmin;
use App\Models\User;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCaseWithDatabase;

#[CoversClass(EnsureUserIsAdmin::class)]
class AdminAccessTest extends TestCaseWithDatabase
{
    protected function setUp(): void
    {
        parent::setUp();
        Config::set('auth.super_admins', []);
    }

    /**
     * @return array<int, array<int, string>>
     */
    public static function adminRoutes(): array
    {
        return [
            ['admin.overview'],
            ['admin.organizations.index'],
            ['admin.users.index'],
            ['admin.subscriptions.index'],
            ['admin.audits.index'],
            ['admin.failed-jobs.index'],
            ['admin.tokens.index'],
            ['admin.invitations.index'],
        ];
    }

    #[DataProvider('adminRoutes')]
    public function test_a_normal_user_is_refused(string $routeName): void
    {
        // Arrange
        $user = User::factory()->withPersonalOrganization()->create();
        $this->actingAs($user);

        // Act
        $response = $this->get(route($routeName));

        // Assert
        $response->assertForbidden();
    }

    #[DataProvider('adminRoutes')]
    public function test_a_super_admin_is_let_in(string $routeName): void
    {
        // Arrange
        $this->actingAs($this->admin());

        // Act
        $response = $this->get(route($routeName));

        // Assert
        $response->assertOk();
    }

    public function test_a_guest_is_sent_to_the_login_screen(): void
    {
        // Act
        $response = $this->get(route('admin.overview'));

        // Assert
        $response->assertRedirect(route('login'));
    }

    public function test_an_admin_with_an_unverified_email_is_refused(): void
    {
        // Arrange
        $user = User::factory()->withPersonalOrganization()->unverified()->create([
            'is_admin' => true,
        ]);
        $this->actingAs($user);

        // Act
        $response = $this->get(route('admin.overview'));

        // Assert
        // The verified middleware runs first and sends them off to prove the address, which is the
        // same outcome by a different route: no admin screen without a verified email.
        $response->assertRedirect(route('verification.notice'));
    }

    public function test_the_environment_list_grants_access_without_the_flag(): void
    {
        // Arrange
        Config::set('auth.super_admins', ['configured@example.com']);
        $user = User::factory()->withPersonalOrganization()->create([
            'email' => 'configured@example.com',
            'is_admin' => false,
        ]);
        $this->actingAs($user);

        // Act
        $response = $this->get(route('admin.overview'));

        // Assert
        $response->assertOk();
    }

    private function admin(): User
    {
        return User::factory()->withPersonalOrganization()->create([
            'is_admin' => true,
        ]);
    }
}
