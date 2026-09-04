<?php

declare(strict_types=1);

namespace Tests\Unit\Listeners;

use App\Enums\BillingInterval;
use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionStatus;
use App\Listeners\Billing\SyncSubscriptionFromStripe;
use App\Models\Organization;
use App\Models\OrganizationSubscription;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCaseWithDatabase;

/**
 * Mapping Stripe's subscription state onto the entitlement record.
 *
 * Written against the Cashier tables directly rather than against Stripe, because what is worth
 * proving here is the translation - a wrong status mapping either bills somebody who cancelled or
 * locks out somebody who paid.
 */
#[CoversClass(SyncSubscriptionFromStripe::class)]
class SyncSubscriptionFromStripeTest extends TestCaseWithDatabase
{
    protected bool $mockBillingContract = false;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('billing.prices.professional.monthly', 'price_monthly');
        Config::set('billing.prices.professional.yearly', 'price_yearly');
        Config::set('cashier.currency', 'gbp');
    }

    private function organizationWithStripeSubscription(
        string $stripeStatus,
        ?Carbon $endsAt = null,
        string $price = 'price_yearly',
        int $quantity = 3,
    ): Organization {
        $organization = Organization::factory()->create();

        DB::table('subscriptions')->insert([
            'organization_id' => $organization->getKey(),
            'type' => 'default',
            'stripe_id' => 'sub_'.uniqid(),
            'stripe_status' => $stripeStatus,
            'stripe_price' => $price,
            'quantity' => $quantity,
            'trial_ends_at' => null,
            'ends_at' => $endsAt,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $organization;
    }

    public function test_an_active_stripe_subscription_becomes_an_active_professional_record(): void
    {
        // Arrange
        $organization = $this->organizationWithStripeSubscription('active');

        // Act
        app(SyncSubscriptionFromStripe::class)->apply($organization);

        // Assert
        $record = $organization->billingRecord()->first();
        $this->assertNotNull($record);
        $this->assertSame(SubscriptionPlan::Professional, $record->plan);
        $this->assertSame(SubscriptionStatus::Active, $record->status);
        $this->assertSame(3, $record->seats);
        $this->assertSame('GBP', $record->currency);
        $this->assertSame(BillingInterval::Yearly, $record->billing_interval);
    }

    public function test_the_monthly_price_maps_to_the_monthly_interval(): void
    {
        // Arrange
        $organization = $this->organizationWithStripeSubscription('active', price: 'price_monthly');

        // Act
        app(SyncSubscriptionFromStripe::class)->apply($organization);

        // Assert
        $this->assertSame(BillingInterval::Monthly, $organization->billingRecord()->first()?->billing_interval);
    }

    /**
     * @return array<int, array{0: string, 1: SubscriptionStatus}>
     */
    public static function stripeStatuses(): array
    {
        return [
            ['active', SubscriptionStatus::Active],
            ['past_due', SubscriptionStatus::PastDue],
            ['unpaid', SubscriptionStatus::PastDue],
            ['incomplete_expired', SubscriptionStatus::Expired],
        ];
    }

    #[DataProvider('stripeStatuses')]
    public function test_stripe_statuses_map_onto_this_applications_vocabulary(string $stripe, SubscriptionStatus $expected): void
    {
        // Arrange
        $organization = $this->organizationWithStripeSubscription($stripe);

        // Act
        app(SyncSubscriptionFromStripe::class)->apply($organization);

        // Assert
        $this->assertSame($expected, $organization->billingRecord()->first()?->status);
    }

    public function test_a_subscription_cancelled_but_still_inside_its_paid_period_stays_entitled(): void
    {
        // Arrange
        $endsAt = Carbon::now()->addWeeks(2);
        $organization = $this->organizationWithStripeSubscription('active', $endsAt);

        // Act
        app(SyncSubscriptionFromStripe::class)->apply($organization);

        // Assert
        $record = $organization->billingRecord()->first();
        $this->assertSame(SubscriptionStatus::Cancelled, $record?->status);
        // Cancelled counts until the period runs out, which is the whole point of ends_at.
        $this->assertTrue($record?->isActive());
    }

    public function test_a_cancelled_subscription_past_its_end_date_is_expired(): void
    {
        // Arrange
        $organization = $this->organizationWithStripeSubscription('canceled', Carbon::now()->subDay());

        // Act
        app(SyncSubscriptionFromStripe::class)->apply($organization);

        // Assert
        $record = $organization->billingRecord()->first();
        $this->assertSame(SubscriptionStatus::Expired, $record?->status);
        $this->assertFalse($record?->isActive());
    }

    public function test_an_organization_with_no_stripe_subscription_drops_to_free(): void
    {
        // Arrange
        $organization = Organization::factory()->create();
        OrganizationSubscription::factory()->forOrganization($organization)->create();

        // Act
        app(SyncSubscriptionFromStripe::class)->apply($organization);

        // Assert
        $record = $organization->billingRecord()->first();
        $this->assertSame(SubscriptionPlan::Free, $record?->plan);
        $this->assertSame(SubscriptionStatus::Expired, $record?->status);
    }

    public function test_an_existing_record_is_updated_rather_than_duplicated(): void
    {
        // Arrange
        $organization = $this->organizationWithStripeSubscription('active');
        OrganizationSubscription::factory()->forOrganization($organization)->free()->create();

        // Act
        app(SyncSubscriptionFromStripe::class)->apply($organization);

        // Assert
        $this->assertSame(
            1,
            OrganizationSubscription::query()->whereBelongsTo($organization, 'organization')->count()
        );
        $this->assertSame(SubscriptionPlan::Professional, $organization->billingRecord()->first()?->plan);
    }

    public function test_the_stripe_subscription_id_is_kept_as_the_external_reference(): void
    {
        // Arrange
        $organization = $this->organizationWithStripeSubscription('active');

        // Act
        app(SyncSubscriptionFromStripe::class)->apply($organization);

        // Assert
        $this->assertStringStartsWith('sub_', (string) $organization->billingRecord()->first()?->external_reference);
    }
}
