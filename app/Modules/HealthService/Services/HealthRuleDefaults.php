<?php

namespace App\Modules\HealthService\Services;

use App\Models\HealthResultRule;

/**
 * The guideline categories the app has always used (WHO BMI, ADA blood sugar in mg/dL, 2017
 * ACC/AHA blood pressure), as result rules. Seeded by migration, restored by the dashboard's
 * "Reset to guideline defaults", and used whenever a metric has no active rule at all, so a
 * reading is always classified. Texts are read from lang/{ar,en}/health.php.
 */
final class HealthRuleDefaults
{
    /** @return list<array<string, mixed>> attribute rows for HealthResultRule */
    public static function rows(?string $metric = null): array
    {
        $rows = [...self::bmi(), ...self::bloodSugar(), ...self::bloodPressure()];

        return array_values(array_filter($rows, fn (array $row) => $metric === null || $row['metric'] === $metric));
    }

    /** @return list<HealthResultRule> unsaved models, for metrics without any active rule */
    public static function models(string $metric): array
    {
        return array_map(fn (array $row) => new HealthResultRule($row), self::rows($metric));
    }

    private static function bmi(): array
    {
        $ranges = [
            'underweight' => [null, 18.5],
            'normal' => [18.5, 25],
            'overweight' => [25, 30],
            'obese_class_1' => [30, 35],
            'obese_class_2' => [35, 40],
            'obese_class_3' => [40, null],
        ];

        $rows = [];
        $sort = 0;
        foreach ($ranges as $code => [$from, $to]) {
            $rows[] = self::row(HealthResultRule::BMI, null, $code, 0, ++$sort, [$from, $to], [null, null], [
                'label_ar' => __("health.bmi.{$code}.status", [], 'ar'),
                'label_en' => __("health.bmi.{$code}.status", [], 'en'),
                'message_ar' => __("health.bmi.{$code}.description", [], 'ar'),
                'message_en' => __("health.bmi.{$code}.description", [], 'en'),
            ]);
        }

        return $rows;
    }

    private static function bloodSugar(): array
    {
        // Below 70 is low at any time (highest priority); then per reading condition.
        $definitions = [
            ['low', null, 10, [null, 70]],
            ['normal', 'fasting', 5, [null, 100]],
            ['high', 'fasting', 5, [100, null]],
            ['normal', 'after_meal', 5, [null, 140]],
            ['high', 'after_meal', 5, [140, null]],
            ['normal', 'random', 5, [null, 200]],
            ['high', 'random', 5, [200, null]],
        ];

        $rows = [];
        foreach ($definitions as $i => [$code, $condition, $priority, $range]) {
            $rows[] = self::row(HealthResultRule::BLOOD_SUGAR, $condition, $code, $priority, $i + 1, $range, [null, null],
                self::labels("health.blood_sugar.{$code}", "health.legacy.blood_sugar.{$code}"));
        }

        return $rows;
    }

    private static function bloodPressure(): array
    {
        // [code, priority, systolic range, diastolic range]; whole mmHg, so "> 180" is "≥ 181".
        $definitions = [
            ['hypertensive_crisis', 60, [181, null], [121, null]],
            ['stage_2', 50, [140, null], [90, null]],
            ['stage_1', 40, [130, null], [80, null]],
            ['low', 30, [null, 90], [null, 60]],
            ['elevated', 20, [120, null], [null, null]],
            ['normal', 10, [null, null], [null, null]],
        ];

        $rows = [];
        foreach ($definitions as $i => [$code, $priority, $systolic, $diastolic]) {
            $crisis = $code === 'hypertensive_crisis';
            $rows[] = self::row(HealthResultRule::BLOOD_PRESSURE, null, $code, $priority, $i + 1, $systolic, $diastolic,
                self::labels("health.blood_pressure.{$code}", "health.legacy.blood_pressure.{$code}") + [
                    'message_ar' => $crisis ? __('health.blood_pressure.crisis_advice', [], 'ar') : null,
                    'message_en' => $crisis ? __('health.blood_pressure.crisis_advice', [], 'en') : null,
                    'requires_urgent_care' => $crisis,
                ]);
        }

        return $rows;
    }

    private static function labels(string $labelKey, string $legacyKey): array
    {
        $labelAr = __($labelKey, [], 'ar');
        $legacy = __($legacyKey, [], 'ar');

        return [
            'label_ar' => $labelAr,
            'label_en' => __($labelKey, [], 'en'),
            // Kept only where old apps received different wording than the label.
            'legacy_ar' => $legacy !== $legacyKey && $legacy !== $labelAr ? $legacy : null,
        ];
    }

    private static function row(string $metric, ?string $condition, string $code, int $priority, int $sort, array $primary, array $secondary, array $texts): array
    {
        return $texts + [
            'metric' => $metric,
            'condition' => $condition,
            'code' => $code,
            'priority' => $priority,
            'sort_order' => $sort,
            'primary_from' => $primary[0],
            'primary_to' => $primary[1],
            'secondary_from' => $secondary[0],
            'secondary_to' => $secondary[1],
            'legacy_ar' => null,
            'message_ar' => null,
            'message_en' => null,
            'requires_urgent_care' => false,
            'is_active' => true,
        ];
    }
}
