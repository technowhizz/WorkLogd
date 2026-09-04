<?php

declare(strict_types=1);

namespace Tests\Unit\Listeners;

use App\Enums\Role;
use App\Listeners\Billing\SyncSeatsWithStripe;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCaseWithDatabase;

/**
 * Seats follow membership.
 *
 * The cases that matter are the ones where Stripe must *not* be called: an organization with no
 * subscription, one that has cancelled, and one whose count has not moved. A stray call there is
 * either an error or an unexpected charge.
 */
#[CoversClass(SyncSeatsWithStripe::class)]
class SyncSeatsWithStripeTest extends TestCaseWithDatabase
{
    protected bool $mockBillingContract = false;

    private function subscribe(Organization $organization, int $quantity, ?Carbon $endsAt = null, string $status = 'active'): void
    {
        DB::table('subscriptions')->insert([
            'organization_id' => $organization->getKey(),
            'type' => 'default',
            'stripe_id' => 'sub_'.uniqid(),
            'stripe_status' => $status,
            'stripe_price' => 'price_yearly',
            'quantity' => $quantity,
            'ends_at' => $endsAt,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_an_organization_with_no_subscription_never_touches_stripe(): void
    {
        // Arrange
        Http::preventStrayRequests();
        $organization = Organization::factory()->create();

        // Act
        app(SyncSeatsWithStripe::class)->sync($organization);

        // Assert
        Http::assertNothingSent();
    }

    public function test_a_cancelled_subscription_is_not_re_quantified(): void
    {
        // Arrange
        Http::preventStrayRequests();
        $organization = Organization::factory()->create();
        $this->subscribe($organization, 1, Carbon::now()->addWeek());
        $organization->users()->attach(User::factory()->create(), ['role' => Role::Employee->value]);

        // Act
        app(SyncSeatsWithStripe::class)->sync($organization);

        // Assert
        // Somebody serving out their notice should not be charged for seats added during it.
        Http::assertNothingSent();
    }

    public function test_no_call_is_made_when_the_seat_count_has_not_moved(): void
    {
        // Arrange
        Http::preventStrayRequests();
        $organization = Organization::factory()->create();
        $organization->users()->attach(User::factory()->create(), ['role' => Role::Employee->value]);
        $this->subscribe($organization, 1);

        // Act
        app(SyncSeatsWithStripe::class)->sync($organization);

        // Assert
        Http::assertNothingSent();
    }

    public function test_placeholder_members_are_not_counted_as_seats(): void
    {
        // Arrange
        Http::preventStrayRequests();
        $organization = Organization::factory()->create();
        $organization->users()->attach(User::factory()->create(), ['role' => Role::Employee->value]);
        $organization->users()->attach(
            User::factory()->create(['is_placeholder' => true]),
            ['role' => Role::Employee->value]
        );
        // One real member, so a subscription already sized to one has nothing to do.
        $this->subscribe($organization, 1);

        // Act
        app(SyncSeatsWithStripe::class)->sync($organization);

        // Assert
        Http::assertNothingSent();
    }
}
