<?php

declare(strict_types=1);

namespace Tests\Unit\Endpoint\Web\Admin;

use App\Http\Controllers\Web\Admin\ImpersonationController;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCaseWithDatabase;

#[CoversClass(ImpersonationController::class)]
class AdminImpersonationTest extends TestCaseWithDatabase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('auth.super_admins', []);
        $this->admin = User::factory()->withPersonalOrganization()->create(['is_admin' => true]);
        $this->actingAs($this->admin);
    }

    public function test_an_admin_can_sign_in_as_another_user(): void
    {
        // Arrange
        $target = User::factory()->withPersonalOrganization()->create();

        // Act
        $response = $this->post(route('admin.users.impersonate', $target->getKey()));

        // Assert
        $response->assertRedirect(route('dashboard'));
        $this->assertTrue(Auth::guard('web')->user()?->is($target));
        $this->assertSame($this->admin->getKey(), session(ImpersonationController::SESSION_KEY));
    }

    public function test_stopping_puts_the_admin_back(): void
    {
        // Arrange
        $target = User::factory()->withPersonalOrganization()->create();
        $this->post(route('admin.users.impersonate', $target->getKey()));

        // Act
        $response = $this->post(route('admin.stop-impersonating'));

        // Assert
        $response->assertRedirect(route('admin.users.index'));
        $this->assertTrue(Auth::guard('web')->user()?->is($this->admin));
        $this->assertNull(session(ImpersonationController::SESSION_KEY));
    }

    public function test_placeholder_users_cannot_be_impersonated(): void
    {
        // Arrange
        $placeholder = User::factory()->create(['is_placeholder' => true]);

        // Act
        $response = $this->post(route('admin.users.impersonate', $placeholder->getKey()));

        // Assert
        $response->assertSessionHas('bannerStyle', 'danger');
        $this->assertTrue(Auth::guard('web')->user()?->is($this->admin));
    }

    public function test_a_user_who_belongs_to_no_organization_cannot_be_impersonated(): void
    {
        // Arrange
        $target = User::factory()->create();

        // Act
        $response = $this->post(route('admin.users.impersonate', $target->getKey()));

        // Assert
        $response->assertSessionHas('bannerStyle', 'danger');
        $this->assertTrue(Auth::guard('web')->user()?->is($this->admin));
    }

    public function test_an_impersonated_session_is_told_it_is_impersonating(): void
    {
        // Arrange
        $target = User::factory()->withPersonalOrganization()->create(['name' => 'Impersonated Person']);
        $this->post(route('admin.users.impersonate', $target->getKey()));

        // Act
        $response = $this->get(route('dashboard'));

        // Assert
        $response->assertInertia(fn ($page) => $page
            ->where('impersonating.name', 'Impersonated Person')
        );
    }

    public function test_a_normal_user_cannot_start_an_impersonation(): void
    {
        // Arrange
        $this->actingAs(User::factory()->withPersonalOrganization()->create());
        $target = User::factory()->withPersonalOrganization()->create();

        // Act
        $response = $this->post(route('admin.users.impersonate', $target->getKey()));

        // Assert
        $response->assertForbidden();
    }

    public function test_stopping_without_an_impersonation_just_goes_home(): void
    {
        // Act
        $response = $this->post(route('admin.stop-impersonating'));

        // Assert
        $response->assertRedirect(route('dashboard'));
        $this->assertTrue(Auth::guard('web')->user()?->is($this->admin));
    }
}
