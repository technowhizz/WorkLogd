<?php

declare(strict_types=1);

namespace Tests\Unit\Endpoint\Web;

use App\Http\Controllers\Web\BillingController;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCaseWithDatabase;

#[CoversClass(BillingController::class)]
class BillingEndpointTest extends TestCaseWithDatabase
{
    protected bool $mockBillingContract = false;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('billing.prices.professional.monthly', 'price_monthly');
        Config::set('billing.prices.professional.yearly', 'price_yearly');
    }

    public function test_an_owner_sees_the_billing_page(): void
    {
        // Arrange
        $data = $this->createUserWithPermission(['billing'], isOwner: true);
        $this->actingAs($data->user);

        // Act
        $response = $this->get(route('billing.show'));

        // Assert
        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Billing')
            ->where('subscription', null)
            ->has('seats')
            ->where('prices.monthly', 'price_monthly')
            ->where('prices.yearly', 'price_yearly')
        );
    }

    public function test_a_member_without_the_billing_permission_is_refused(): void
    {
        // Arrange
        $data = $this->createUserWithPermission([]);
        $this->actingAs($data->user);

        // Act
        $response = $this->get(route('billing.show'));

        // Assert
        $response->assertForbidden();
    }

    public function test_a_guest_is_sent_to_the_login_screen(): void
    {
        // Act
        $response = $this->get(route('billing.show'));

        // Assert
        $response->assertRedirect(route('login'));
    }

    public function test_the_page_reports_the_current_seat_count(): void
    {
        // Arrange
        $data = $this->createUserWithPermission(['billing'], isOwner: true);
        $this->actingAs($data->user);

        // Act
        $response = $this->get(route('billing.show'));

        // Assert
        $response->assertInertia(fn (Assert $page) => $page->where('seats', 1));
    }

    public function test_an_active_stripe_subscription_is_shown(): void
    {
        // Arrange
        $data = $this->createUserWithPermission(['billing'], isOwner: true);
        DB::table('subscriptions')->insert([
            'organization_id' => $data->organization->getKey(),
            'type' => 'default',
            'stripe_id' => 'sub_test',
            'stripe_status' => 'active',
            'stripe_price' => 'price_yearly',
            'quantity' => 4,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->actingAs($data->user);

        // Act
        $response = $this->get(route('billing.show'));

        // Assert
        $response->assertInertia(fn (Assert $page) => $page
            ->where('subscription.status', 'active')
            ->where('subscription.quantity', 4)
            ->where('subscription.interval', 'yearly')
            ->where('subscription.is_active', true)
        );
    }

    public function test_checkout_says_so_when_no_price_is_configured(): void
    {
        // Arrange
        Config::set('billing.prices.professional.yearly', null);
        $data = $this->createUserWithPermission(['billing'], isOwner: true);
        $this->actingAs($data->user);

        // Act
        $response = $this->post(route('billing.checkout'), ['interval' => 'yearly']);

        // Assert
        $response->assertSessionHas('bannerStyle', 'danger');
    }

    public function test_checkout_rejects_an_interval_that_is_not_offered(): void
    {
        // Arrange
        $data = $this->createUserWithPermission(['billing'], isOwner: true);
        $this->actingAs($data->user);

        // Act
        $response = $this->post(route('billing.checkout'), ['interval' => 'fortnightly']);

        // Assert
        $response->assertSessionHasErrors('interval');
    }

    public function test_checkout_is_refused_without_the_billing_permission(): void
    {
        // Arrange
        $data = $this->createUserWithPermission([]);
        $this->actingAs($data->user);

        // Act
        $response = $this->post(route('billing.checkout'), ['interval' => 'yearly']);

        // Assert
        $response->assertForbidden();
    }

    public function test_the_portal_says_so_when_the_organization_has_never_been_billed(): void
    {
        // Arrange
        $data = $this->createUserWithPermission(['billing'], isOwner: true);
        $this->actingAs($data->user);

        // Act
        $response = $this->get(route('billing.portal'));

        // Assert
        $response->assertSessionHas('bannerStyle', 'danger');
    }

    public function test_the_page_says_whether_limits_are_actually_being_applied(): void
    {
        // Arrange
        Config::set('billing.enforce', false);
        $data = $this->createUserWithPermission(['billing'], isOwner: true);
        $this->actingAs($data->user);

        // Act
        $response = $this->get(route('billing.show'));

        // Assert
        $response->assertInertia(fn (Assert $page) => $page
            ->where('enforced', false)
            ->where('entitled', true)
        );
        $this->assertNotNull(Organization::query()->whereKey($data->organization->getKey())->first());
        $this->assertInstanceOf(User::class, $data->user);
    }
}
