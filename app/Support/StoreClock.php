<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * The current time as the pharmacy's wall clock shows it.
 *
 * Flash sale, discount rule and coupon dates are entered by the admin in local time and
 * stored without a timezone, so Eloquent reads them as if they were in the app timezone
 * (UTC). Comparing them with this value instead of now() keeps every promotion check
 * (display, cart, coupon preview and checkout) on the same clock.
 */
final class StoreClock
{
    public static function now(): Carbon
    {
        return Carbon::now(config('pharmacy.timezone'))->shiftTimezone(config('app.timezone'));
    }
}
