<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BillingInterval;
use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionStatus;
use App\Models\Concerns\CustomAuditable;
use App\Models\Concerns\HasUuids;
use App\Service\SubscriptionBillingService;
use Database\Factories\OrganizationSubscriptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * The billing arrangement an organization is currently on.
 *
 * The record is the source of truth for what an organization is entitled to, but only once
 * `billing.enforce` is on - see {@see SubscriptionBillingService}.
 *
 * @property string $id
 * @property string $organization_id
 * @property SubscriptionPlan $plan
 * @property SubscriptionStatus $status
 * @property Carbon|null $trial_ends_at
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 * @property int|null $seats
 * @property int|null $price
 * @property string|null $currency
 * @property BillingInterval|null $billing_interval
 * @property string|null $external_reference
 * @property string|null $note
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Organization $organization
 *
 * @method static OrganizationSubscriptionFactory factory()
 */
class OrganizationSubscription extends Model implements AuditableContract
{
    use CustomAuditable;

    /** @use HasFactory<OrganizationSubscriptionFactory> */
    use HasFactory;

    use HasUuids;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'organization_subscriptions';

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'plan' => SubscriptionPlan::class,
        'status' => SubscriptionStatus::class,
        'trial_ends_at' => 'datetime',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'seats' => 'integer',
        'price' => 'integer',
        'currency' => 'string',
        'billing_interval' => BillingInterval::class,
        'external_reference' => 'string',
        'note' => 'string',
    ];

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'plan' => SubscriptionPlan::Free,
        'status' => SubscriptionStatus::Active,
    ];

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }

    /**
     * Whether the organization is inside a trial that has not run out yet.
     */
    public function isOnTrial(): bool
    {
        return $this->status === SubscriptionStatus::Trialing
            && $this->trial_ends_at !== null
            && $this->trial_ends_at->isFuture();
    }

    /**
     * Whether the organization is currently entitled to the paid features.
     *
     * A cancelled subscription still counts until the period it was paid for is over, which is
     * what `ends_at` records.
     */
    public function isActive(): bool
    {
        if (! $this->plan->isPaid() || ! $this->status->isEntitled()) {
            return false;
        }

        if ($this->starts_at !== null && $this->starts_at->isFuture()) {
            return false;
        }

        return $this->ends_at === null || $this->ends_at->isFuture();
    }

    /**
     * What the subscription is worth per month, in the minor unit of its currency.
     *
     * Yearly prices are spread across the year rather than counted in the month they land in, so
     * that the number can be summed into a monthly recurring revenue figure.
     */
    public function monthlyPrice(): ?int
    {
        if ($this->price === null || $this->billing_interval === null) {
            return null;
        }

        if (! $this->isActive()) {
            return 0;
        }

        return (int) round($this->price * $this->billing_interval->paymentsPerYear() / 12);
    }
}
