<?php

declare(strict_types=1);

namespace Tests\Unit\Endpoint\Web\Admin;

use App\Http\Controllers\Web\Admin\DashboardController;
use App\Models\User;
use Illuminate\Support\Facades\Config;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCaseWithDatabase;

#[CoversClass(DashboardController::class)]
class AdminOverviewTest extends TestCaseWithDatabase
{
    protected function setUp(): void
    {
        parent::setUp();
        Config::set('auth.super_admins', []);
        $this->actingAs(User::factory()->withPersonalOrganization()->create(['is_admin' => true]));
    }

    public function test_shows_the_instance_counts_billing_and_charts(): void
    {
        // Arrange
        User::factory()->withPersonalOrganization()->createMany(2);

        // Act
        $response = $this->get(route('admin.overview'));

        // Assert
        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Overview')
            ->where('users.total', 3)
            ->where('users.admins', 1)
            ->where('organizations.total', 3)
            ->has('billing.monthly_revenue')
            ->has('charts.registrations')
            ->has('charts.timeEntriesCreated')
            ->has('charts.timeEntriesImported')
            ->has('server.version')
            ->where('range', 'week')
        );
    }

    public function test_the_range_switches_the_charts_to_monthly_buckets_over_a_year(): void
    {
        // Act
        $response = $this->get(route('admin.overview', ['range' => 'year']));

        // Assert
        $response->assertInertia(fn (Assert $page) => $page
            ->where('range', 'year')
            // A year of monthly buckets is 13 points, against 8 for a week of daily ones.
            ->has('charts.registrations', 13)
        );
    }

    public function test_an_unknown_range_falls_back_to_the_week(): void
    {
        // Act
        $response = $this->get(route('admin.overview', ['range' => 'decade']));

        // Assert
        $response->assertInertia(fn (Assert $page) => $page->where('range', 'week'));
    }
}
