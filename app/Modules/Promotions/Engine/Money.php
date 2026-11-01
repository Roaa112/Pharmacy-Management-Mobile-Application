<?php

namespace App\Modules\Promotions\Engine;

use App\Support\Money as BaseMoney;

/** Output formatting for engine amounts (minor units in, the API's float numbers out). */
final class Money
{
    public static function out(int $minor): float
    {
        return BaseMoney::fromMinor($minor);
    }
}
