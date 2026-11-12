<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One result category of a health measurement (BMI, blood sugar, blood pressure), managed from
 * the dashboard: when it applies (thresholds) and what the customer is told (label, message).
 *
 * Ranges are "from ≤ value < to"; an empty bound is open. A rule matches when the primary value
 * is in its primary range OR (blood pressure) the diastolic value is in its secondary range; a
 * rule without any range matches every reading (a catch-all such as "normal"). Of the matching
 * rules, the highest priority wins — so "the higher category of the two numbers wins" for blood
 * pressure is expressed by giving higher categories a higher priority.
 */
class HealthResultRule extends Model
{
    public const BMI = 'bmi';
    public const BLOOD_SUGAR = 'blood_sugar';
    public const BLOOD_PRESSURE = 'blood_pressure';

    public const METRICS = [self::BMI, self::BLOOD_SUGAR, self::BLOOD_PRESSURE];

    /** Blood sugar reading groups (BloodSugarCondition maps onto them). null = any condition. */
    public const SUGAR_CONDITIONS = ['fasting', 'after_meal', 'random'];

    protected $fillable = [
        'metric', 'condition', 'code', 'priority', 'sort_order',
        'primary_from', 'primary_to', 'secondary_from', 'secondary_to',
        'label_ar', 'label_en', 'legacy_ar', 'message_ar', 'message_en',
        'requires_urgent_care', 'is_active',
    ];

    protected $casts = [
        'priority' => 'integer',
        'sort_order' => 'integer',
        'primary_from' => 'float',
        'primary_to' => 'float',
        'secondary_from' => 'float',
        'secondary_to' => 'float',
        'requires_urgent_care' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function hasPrimaryRange(): bool
    {
        return $this->primary_from !== null || $this->primary_to !== null;
    }

    public function hasSecondaryRange(): bool
    {
        return $this->secondary_from !== null || $this->secondary_to !== null;
    }

    public function matches(float $primary, ?float $secondary = null): bool
    {
        if (!$this->hasPrimaryRange() && !$this->hasSecondaryRange()) {
            return true;
        }

        return ($this->hasPrimaryRange() && self::inRange($primary, $this->primary_from, $this->primary_to))
            || ($secondary !== null && $this->hasSecondaryRange() && self::inRange($secondary, $this->secondary_from, $this->secondary_to));
    }

    public static function inRange(float $value, ?float $from, ?float $to): bool
    {
        return ($from === null || $value >= $from) && ($to === null || $value < $to);
    }

    public function label(string $locale): string
    {
        return ($locale === 'en' ? $this->label_en : $this->label_ar) ?: (string) $this->label_ar;
    }

    /** The message shown with the result, or null when the category has none. */
    public function message(string $locale): ?string
    {
        $message = $locale === 'en' ? $this->message_en : $this->message_ar;

        return filled($message) ? $message : null;
    }

    /** The exact Arabic text older apps receive in `status` / `evaluation`. */
    public function legacyText(): string
    {
        return filled($this->legacy_ar) ? $this->legacy_ar : (string) $this->label_ar;
    }

    public function rangeText(string $unit = ''): string
    {
        $parts = [];
        foreach ([[$this->primary_from, $this->primary_to, $this->metric === self::BLOOD_PRESSURE ? 'systolic' : 'value'],
                  [$this->secondary_from, $this->secondary_to, 'diastolic']] as [$from, $to, $name]) {
            if ($from === null && $to === null) {
                continue;
            }
            $parts[] = match (true) {
                $from !== null && $to !== null => "{$name} ".self::number($from)." to under ".self::number($to),
                $from !== null => "{$name} ≥ ".self::number($from),
                default => "{$name} < ".self::number($to),
            };
        }

        return $parts ? implode(' or ', $parts).($unit ? " {$unit}" : '') : 'any reading (catch-all)';
    }

    private static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
