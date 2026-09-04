<?php

declare(strict_types=1);

namespace App\Enums;

use Datomatic\LaravelEnumHelper\LaravelEnumHelper;

enum SubscriptionStatus: string
{
    use LaravelEnumHelper;

    case Trialing = 'trialing';
    case Active = 'active';
    /**
     * Payment has failed but the organization keeps its features while it is chased up.
     */
    case PastDue = 'past-due';
    /**
     * Cancelled, but paid up until the end of the current period.
     */
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    /**
     * Whether the status is one where the organization still gets what it pays for.
     */
    public function isEntitled(): bool
    {
        return $this === self::Active || $this === self::PastDue || $this === self::Cancelled;
    }

    /**
     * @return array<string, string>
     */
    public static function toSelectArray(): array
    {
        $selectArray = [];
        foreach (self::cases() as $case) {
            $selectArray[$case->value] = (string) __('enum.subscription_status.'.$case->value);
        }

        return $selectArray;
    }
}
