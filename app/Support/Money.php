<?php

namespace App\Support;

/**
 * Money arithmetic in integer minor units (1/100), so prices, discounts and totals
 * never go through binary floating point. Amounts are stored as DECIMAL(10,2).
 */
final class Money
{
    /**
     * "12.34" / 12.34 / 12 -> 1234. Non-numeric or empty values count as 0.
     */
    public static function toMinor(mixed $amount): int
    {
        if ($amount === null || $amount === '' || !is_numeric($amount)) {
            return 0;
        }

        if (is_int($amount)) {
            return $amount * 100;
        }

        if (is_float($amount)) {
            return (int) round($amount * 100);
        }

        // Parse decimal strings exactly instead of casting through float.
        $amount = trim((string) $amount);
        $negative = str_starts_with($amount, '-');
        $amount = ltrim($amount, '+-');

        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');
        $fraction = str_pad($fraction, 3, '0');

        $minor = ((int) $whole) * 100 + (int) substr($fraction, 0, 2);

        // Round half up on the third decimal.
        if ((int) $fraction[2] >= 5) {
            $minor++;
        }

        return $negative ? -$minor : $minor;
    }

    /**
     * 1234 -> 12.34 (float, matching the JSON number type the API already returns).
     */
    public static function fromMinor(int $minor): float
    {
        return $minor / 100;
    }

    /**
     * Price after a percentage discount, rounded half up: (100.00, "15") -> 85.00.
     */
    public static function applyPercent(int $minor, mixed $percent): int
    {
        $basisPoints = min(max(self::toMinor($percent), 0), 10000);

        return self::divideRoundHalfUp($minor * (10000 - $basisPoints), 10000);
    }

    /**
     * The percentage of an amount, rounded half up: (90.00, "10") -> 9.00.
     */
    public static function percentOf(int $minor, mixed $percent): int
    {
        $basisPoints = min(max(self::toMinor($percent), 0), 10000);

        return self::divideRoundHalfUp($minor * $basisPoints, 10000);
    }

    private static function divideRoundHalfUp(int $numerator, int $denominator): int
    {
        return intdiv($numerator + intdiv($denominator, 2), $denominator);
    }
}
