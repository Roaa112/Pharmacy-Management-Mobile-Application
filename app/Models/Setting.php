<?php

namespace App\Models;

use App\Support\Money;
use App\Traits\Translatable;
use Illuminate\Database\Eloquent\Model;

/**
 * The store's single settings row: contact details, legal texts and typed business policies.
 * Always read it through Setting::current(); there is never more than one row in use.
 *
 * Security limits (OTP, rate limits, token lifetime) are deliberately not here: they live in
 * config/env so a dashboard account cannot weaken them.
 */
class Setting extends Model
{
    use Translatable;

    protected $fillable = [
        'privacy_policy_ar',
        'privacy_policy_en',
        'terms_conditions_ar',
        'terms_conditions_en',
        'facebook',
        'instagram',
        'tiktok',
        'youtube',
        'phone_number',
        'map_location_url',
        'spend_x',
        'get_y',
        'points_validity_days',
        'low_stock_threshold',
        'return_window_days',
        'customer_can_cancel_pending',
        'coupon_applies_to_delivery',
        'vaccine_reminders_enabled',
        'medication_reminders_enabled',
        'coupon_release_on_cancel',
    ];

    protected $casts = [
        'customer_can_cancel_pending' => 'boolean',
        'coupon_applies_to_delivery' => 'boolean',
        'vaccine_reminders_enabled' => 'boolean',
        'medication_reminders_enabled' => 'boolean',
        'coupon_release_on_cancel' => 'boolean',
    ];

    protected $appends = [
        'privacy_policy',
        'terms_conditions',
        // Compatibility only, always null: see getZaincashAttribute().
        'zaincash',
    ];

    protected $hidden = [
        'privacy_policy_ar',
        'privacy_policy_en',
        'terms_conditions_ar',
        'terms_conditions_en',
    ];

    /** The row in use (the oldest one, as the app always read it). Created on first use. */
    public static function current(): self
    {
        return static::orderBy('id')->first() ?? static::create([])->fresh();
    }

    public function getPrivacyPolicyAttribute()
    {
        return $this->getTranslatedAttribute('privacy_policy');
    }

    public function getTermsConditionsAttribute()
    {
        return $this->getTranslatedAttribute('terms_conditions');
    }

    // ---- Typed policies (defaults = the behaviour before these settings existed) ----

    public function pointsSpendMinor(): int
    {
        return max(0, Money::toMinor($this->spend_x));
    }

    public function pointsPerSpend(): int
    {
        return max(0, (int) $this->get_y);
    }

    /**
     * Days points stay valid. 0 or empty means they expire the day they are earned (decision
     * D10 is still open; this keeps the original behaviour).
     */
    public function pointsValidityDays(): int
    {
        return max(0, (int) $this->points_validity_days);
    }

    public function lowStockThreshold(): int
    {
        return $this->low_stock_threshold ?? (int) config('pharmacy.low_stock_threshold');
    }

    /** null = returns are not limited by time (original behaviour). */
    public function returnWindowDays(): ?int
    {
        return $this->return_window_days === null ? null : (int) $this->return_window_days;
    }

    /**
     * ZainCash has been removed as an active payment provider. Historical ZainCash records are
     * preserved (orders, payments and the legacy payment_methods row, which keeps the old wallet
     * number). Its setting column was dropped (2026_11_05_000001); the settings API keeps the key,
     * always null, so app versions that read it stop offering ZainCash instead of failing to parse.
     */
    public function getZaincashAttribute(): ?string
    {
        return null;
    }

    /**
     * Qi Card was retired too; its column keeps the historical value, the API returns null.
     */
    public function getKeycardAttribute(): ?string
    {
        return null;
    }
}
