<?php

declare(strict_types=1);

namespace App\Enums;

use Datomatic\LaravelEnumHelper\LaravelEnumHelper;

enum BillingInterval: string
{
    use LaravelEnumHelper;

    case Monthly = 'monthly';
    case Yearly = 'yearly';

    /**
     * How many times a year the organization is charged, used to put every price on the same
     * monthly footing for reporting.
     */
    public function paymentsPerYear(): int
    {
        return match ($this) {
            self::Monthly => 12,
            self::Yearly => 1,
        };
    }

    /**
     * @return array<string, string>
     */
    public static function toSelectArray(): array
    {
        $selectArray = [];
        foreach (self::cases() as $case) {
            $selectArray[$case->value] = (string) __('enum.billing_interval.'.$case->value);
        }

        return $selectArray;
    }
}
