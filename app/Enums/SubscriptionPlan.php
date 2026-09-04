<?php

declare(strict_types=1);

namespace App\Enums;

use Datomatic\LaravelEnumHelper\LaravelEnumHelper;

enum SubscriptionPlan: string
{
    use LaravelEnumHelper;

    case Free = 'free';
    case Professional = 'professional';
    case Enterprise = 'enterprise';

    /**
     * Whether the plan is one that unlocks the paid features.
     */
    public function isPaid(): bool
    {
        return $this !== self::Free;
    }

    /**
     * @return array<string, string>
     */
    public static function toSelectArray(): array
    {
        $selectArray = [];
        foreach (self::cases() as $case) {
            $selectArray[$case->value] = (string) __('enum.subscription_plan.'.$case->value);
        }

        return $selectArray;
    }
}
