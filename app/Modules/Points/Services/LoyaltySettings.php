<?php

namespace App\Modules\Points\Services;

use App\Models\Setting;
use App\Support\Money;

/**
 * The loyalty rules, read from the settings row (edited on Loyalty › Settings). Defaults are the
 * behaviour before the rules were configurable: earn on the products subtotal before delivery and
 * coupon, on every payment method, usable once the order is completed, no checkout redemption.
 *
 * Amounts are in minor units (Money); point value is kept in 1/10 000 of the currency so a point
 * can be worth less than one minor unit.
 */
final class LoyaltySettings
{
    public const ACTIVATE_IMMEDIATELY = 'immediate';
    public const ACTIVATE_ON_PAYMENT = 'paid';
    public const ACTIVATE_ON_COMPLETION = 'completed';
    public const ACTIVATIONS = [self::ACTIVATE_IMMEDIATELY, self::ACTIVATE_ON_PAYMENT, self::ACTIVATE_ON_COMPLETION];

    public const BASIS_SUBTOTAL = 'subtotal';
    public const BASIS_PAID = 'paid';
    public const BASES = [self::BASIS_SUBTOTAL, self::BASIS_PAID];

    /** The expiry periods the settings screen offers (days; null = never). Any 0–3650 is accepted. */
    public const EXPIRY_PRESETS = [30, 60, 90, 180, 365];

    public function __construct(private Setting $setting)
    {
    }

    public static function current(): self
    {
        return new self(Setting::current());
    }

    public function setting(): Setting
    {
        return $this->setting;
    }

    public function enabled(): bool
    {
        return (bool) ($this->setting->loyalty_enabled ?? true);
    }

    public function spendMinor(): int
    {
        return $this->setting->pointsSpendMinor();
    }

    public function pointsPerSpend(): int
    {
        return $this->setting->pointsPerSpend();
    }

    public function minOrderMinor(): int
    {
        return max(0, Money::toMinor($this->setting->loyalty_min_order_amount ?? 0));
    }

    public function maxPointsPerOrder(): ?int
    {
        return $this->positiveOrNull($this->setting->loyalty_max_points_per_order);
    }

    public function basis(): string
    {
        return in_array($this->setting->loyalty_earn_basis, self::BASES, true) ? $this->setting->loyalty_earn_basis : self::BASIS_SUBTOTAL;
    }

    public function earnOnShipping(): bool
    {
        return (bool) $this->setting->loyalty_earn_on_shipping;
    }

    public function discountsReducePoints(): bool
    {
        return (bool) $this->setting->loyalty_discounts_reduce_points;
    }

    public function earnOnCod(): bool
    {
        return (bool) ($this->setting->loyalty_earn_on_cod ?? true);
    }

    public function activation(): string
    {
        return in_array($this->setting->loyalty_activation, self::ACTIVATIONS, true) ? $this->setting->loyalty_activation : self::ACTIVATE_ON_COMPLETION;
    }

    public function neverExpires(): bool
    {
        return (bool) $this->setting->points_never_expire;
    }

    /** null when points never expire. 0 keeps the original meaning: they lapse the day they become usable. */
    public function validityDays(): ?int
    {
        return $this->neverExpires() ? null : $this->setting->pointsValidityDays();
    }

    /** Currency per point in 1/10 000 units; 0 = points cannot pay at checkout. */
    public function pointValueMicro(): int
    {
        return max(0, (int) round(((float) ($this->setting->loyalty_point_value ?? 0)) * 10000));
    }

    public function checkoutRedemptionEnabled(): bool
    {
        return $this->enabled() && $this->pointValueMicro() > 0;
    }

    /** What $points pay at checkout, in minor units (rounded down: never more than they are worth). */
    public function valueMinor(int $points): int
    {
        return intdiv($points * $this->pointValueMicro(), 100);
    }

    /** The most points that fit in $minor (rounded down). */
    public function pointsWorth(int $minor): int
    {
        return $this->pointValueMicro() > 0 ? intdiv($minor * 100, $this->pointValueMicro()) : 0;
    }

    public function minRedeemPoints(): int
    {
        return max(0, (int) $this->setting->loyalty_min_redeem_points);
    }

    public function maxRedeemPointsPerOrder(): ?int
    {
        return $this->positiveOrNull($this->setting->loyalty_max_redeem_points_per_order);
    }

    public function combineWithDiscounts(): bool
    {
        return (bool) ($this->setting->loyalty_combine_with_discounts ?? true);
    }

    public function allowPartialRedemption(): bool
    {
        return (bool) ($this->setting->loyalty_allow_partial_redemption ?? true);
    }

    public function allowMultipleRedemptions(): bool
    {
        return (bool) ($this->setting->loyalty_allow_multiple_redemptions ?? true);
    }

    public function expiryWarningDays(): int
    {
        return max(1, (int) ($this->setting->loyalty_expiry_warning_days ?? 7));
    }

    /** Days a reward redemption waits to be collected before it expires (null = never). */
    public function redemptionValidityDays(): ?int
    {
        return $this->positiveOrNull($this->setting->loyalty_redemption_validity_days);
    }

    /** For the API and the settings screen. */
    public function toArray(): array
    {
        return [
            'enabled' => $this->enabled(),
            'earning' => [
                'points_per_spend' => $this->pointsPerSpend(),
                'spend_amount' => Money::fromMinor($this->spendMinor()),
                'min_order_amount' => Money::fromMinor($this->minOrderMinor()),
                'max_points_per_order' => $this->maxPointsPerOrder(),
                'basis' => $this->basis(),
                'includes_shipping' => $this->earnOnShipping(),
                'discounts_reduce_points' => $this->discountsReducePoints(),
                'earn_on_cod' => $this->earnOnCod(),
                'points_become_valid' => $this->activation(),
            ],
            'expiry' => [
                'never_expires' => $this->neverExpires(),
                'validity_days' => $this->validityDays(),
                'warning_days' => $this->expiryWarningDays(),
            ],
            'redemption' => [
                'checkout_enabled' => $this->checkoutRedemptionEnabled(),
                'point_value' => $this->pointValueMicro() / 10000,
                'min_points' => $this->minRedeemPoints(),
                'max_points_per_order' => $this->maxRedeemPointsPerOrder(),
                'combine_with_discounts' => $this->combineWithDiscounts(),
                'partial_allowed' => $this->allowPartialRedemption(),
                'multiple_allowed' => $this->allowMultipleRedemptions(),
                'reward_collection_days' => $this->redemptionValidityDays(),
            ],
        ];
    }

    private function positiveOrNull($value): ?int
    {
        return $value === null || (int) $value <= 0 ? null : (int) $value;
    }
}
