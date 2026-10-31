<?php

namespace Tests\Unit;

use App\Support\Money;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_parses_decimal_strings_exactly(): void
    {
        $this->assertSame(1999, Money::toMinor('19.99'));
        $this->assertSame(1000, Money::toMinor('10'));
        $this->assertSame(1050, Money::toMinor('10.5'));
        $this->assertSame(10, Money::toMinor('0.10'));
        $this->assertSame(1999, Money::toMinor(19.99));
        $this->assertSame(1200, Money::toMinor(12));
        $this->assertSame(-250, Money::toMinor('-2.50'));
    }

    public function test_rounds_the_third_decimal_half_up(): void
    {
        $this->assertSame(29, Money::toMinor('0.285'));
        $this->assertSame(28, Money::toMinor('0.284'));
    }

    public function test_non_numeric_values_count_as_zero(): void
    {
        $this->assertSame(0, Money::toMinor(null));
        $this->assertSame(0, Money::toMinor(''));
        $this->assertSame(0, Money::toMinor('abc'));
    }

    public function test_float_sums_that_drift_in_binary_are_exact_in_minor_units(): void
    {
        $this->assertNotSame(0.3, 0.1 + 0.2);
        $this->assertSame(30, Money::toMinor('0.10') + Money::toMinor('0.20'));
        $this->assertSame(0.3, Money::fromMinor(30));
    }

    public function test_percentage_discounts_round_half_up(): void
    {
        $this->assertSame(8500, Money::applyPercent(10000, '15'));
        $this->assertSame(9, Money::applyPercent(10, '15'));      // 0.085 -> 0.09
        $this->assertSame(854, Money::applyPercent(1005, '15'));  // 8.5425 -> 8.54
        $this->assertSame(0, Money::applyPercent(1999, '100'));
        $this->assertSame(1999, Money::applyPercent(1999, '0'));
        $this->assertSame(0, Money::applyPercent(1999, '150'));   // clamped to 100%
    }

    public function test_percentage_of_an_amount(): void
    {
        $this->assertSame(900, Money::percentOf(9000, '10'));
        $this->assertSame(2, Money::percentOf(15, '12.5'));       // 0.01875 -> 0.02
        $this->assertSame(0, Money::percentOf(9000, '-5'));       // negative clamped to 0
    }
}
