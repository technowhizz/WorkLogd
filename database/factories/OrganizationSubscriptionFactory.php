<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\BillingInterval;
use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionStatus;
use App\Models\Organization;
use App\Models\OrganizationSubscription;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<OrganizationSubscription>
 */
class OrganizationSubscriptionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'plan' => SubscriptionPlan::Professional,
            'status' => SubscriptionStatus::Active,
            'trial_ends_at' => null,
            'starts_at' => Carbon::now()->subMonth(),
            'ends_at' => null,
            'seats' => 10,
            'price' => 1000,
            'currency' => 'EUR',
            'billing_interval' => BillingInterval::Monthly,
            'external_reference' => null,
            'note' => null,
        ];
    }

    public function forOrganization(Organization $organization): self
    {
        return $this->state(fn (array $attributes): array => [
            'organization_id' => $organization->getKey(),
        ]);
    }

    public function plan(SubscriptionPlan $plan): self
    {
        return $this->state(fn (array $attributes): array => [
            'plan' => $plan,
        ]);
    }

    public function status(SubscriptionStatus $status): self
    {
        return $this->state(fn (array $attributes): array => [
            'status' => $status,
        ]);
    }

    public function free(): self
    {
        return $this->state(fn (array $attributes): array => [
            'plan' => SubscriptionPlan::Free,
            'status' => SubscriptionStatus::Active,
            'seats' => null,
            'price' => null,
            'currency' => null,
            'billing_interval' => null,
        ]);
    }

    public function onTrial(?Carbon $until = null): self
    {
        return $this->state(fn (array $attributes): array => [
            'status' => SubscriptionStatus::Trialing,
            'trial_ends_at' => $until ?? Carbon::now()->addDays(14),
        ]);
    }

    public function expiredTrial(): self
    {
        return $this->state(fn (array $attributes): array => [
            'status' => SubscriptionStatus::Trialing,
            'trial_ends_at' => Carbon::now()->subDay(),
        ]);
    }

    public function cancelledAt(Carbon $endsAt): self
    {
        return $this->state(fn (array $attributes): array => [
            'status' => SubscriptionStatus::Cancelled,
            'ends_at' => $endsAt,
        ]);
    }
}
