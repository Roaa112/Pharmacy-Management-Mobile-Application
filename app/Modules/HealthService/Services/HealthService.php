<?php

namespace App\Modules\HealthService\Services;

use App\Enums\BloodSugarCondition;
use App\Models\HealthResultRule;
use App\Models\BloodPressureMeasurement;
use App\Models\BloodSugarMeasurement;
use App\Models\BodyWeightResult;
use App\Models\Child;
use App\Models\OvulationResult;
use App\Models\PregnancyCalculation;
use App\Models\PregnancyWeekContent;
use App\Support\StoreClock;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

/**
 * Health calculations.
 *
 * Dashboard-controlled (Health content): the result categories of BMI, blood sugar and blood
 * pressure — thresholds, labels, messages, urgent flag — are HealthResultRule rows (seeded with
 * the WHO / ADA / ACC-AHA guideline values); the ovulation parameters, BMI ideal range, guideline
 * names and disclaimer are HealthSettings; every other text comes from lang/{ar,en}/health.php.
 * The arithmetic itself (BMI formula, cycle and pregnancy dates) stays in code.
 *
 * Fields older apps may compare against (phase, status, evaluation, trimester...) keep their
 * exact Arabic wording ("legacy"); the *_code fields are stable identifiers and the *_label
 * fields follow Accept-Language.
 */
class HealthService
{
    public function __construct(private VaccineScheduleService $vaccines, private HealthEvaluator $evaluator)
    {
    }

    private ?HealthSettings $settings = null;

    private function settings(): HealthSettings
    {
        return $this->settings ??= HealthSettings::current();
    }

    /** Code, texts and urgency of a classified reading ("unknown" when no rule covers it). */
    private function classification(?HealthResultRule $rule, string $unknownLabelKey, ?string $unknownLegacyKey = null): array
    {
        $locale = api_locale();

        if (!$rule) {
            return [
                'code' => 'unknown',
                'legacy' => __($unknownLegacyKey ?? $unknownLabelKey, [], 'ar'),
                'label' => __($unknownLabelKey, [], $locale),
                'message' => null,
                'message_ar' => null,
                'urgent' => false,
            ];
        }

        return [
            'code' => $rule->code,
            'legacy' => $rule->legacyText(),
            'label' => $rule->label($locale),
            'message' => $rule->message($locale),
            'message_ar' => $rule->message('ar'),
            'urgent' => (bool) $rule->requires_urgent_care,
        ];
    }

    // ---------------------------------------------------------------- history

    /**
     * A user's measurements, newest first. Without page/per_page the full list is returned as
     * before, capped at LEGACY_HISTORY_CAP rows; with them, a normal page (max 100).
     */
    public const LEGACY_HISTORY_CAP = 500;

    public function history(Builder $query, ?int $page, ?int $perPage): array
    {
        $query->orderBy('created_at', 'desc')->orderBy('id', 'desc');

        if ($page === null && $perPage === null) {
            $total = (clone $query)->count();
            $rows = $query->limit(self::LEGACY_HISTORY_CAP)->get();

            return ['data' => $rows, 'meta' => ['total' => $total, 'truncated' => $total > self::LEGACY_HISTORY_CAP]];
        }

        $paginator = $query->paginate(min(max($perPage ?? 20, 1), 100), ['*'], 'page', max($page ?? 1, 1));

        return ['data' => $paginator->items(), 'meta' => [
            'total' => $paginator->total(),
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
        ]];
    }

    public function bloodPressureQuery(int $userId): Builder
    {
        return BloodPressureMeasurement::where('user_id', $userId);
    }

    public function bloodSugarQuery(int $userId): Builder
    {
        return BloodSugarMeasurement::where('user_id', $userId);
    }

    public function weightQuery(int $userId): Builder
    {
        return BodyWeightResult::where('user_id', $userId);
    }

    // -------------------------------------------------------------- ovulation

    /*
     * Ovulation is cycle_length - luteal phase (Health settings, default 14 days); the fertile
     * window is the 5 days before ovulation, ovulation day and 2 days after. The chance of
     * pregnancy per phase is a Health setting (defaults follow Wilcox et al., NEJM 1995).
     */

    public function calculateOvulation($userId, array $validated)
    {
        $startDate = Carbon::parse($validated['start_day_of_cycle'])->startOfDay();
        $cycleLength = (int) $validated['cycle_length'];
        $periodLength = (int) $validated['period_length'];

        $ovulationDay = $this->ovulationDay($startDate, $cycleLength);
        $fertileStart = $ovulationDay->copy()->subDays(5);
        $fertileEnd = $ovulationDay->copy()->addDays(2);

        $phases = [];
        $days = [];

        for ($i = 0; $i < $cycleLength; $i++) {
            $day = $startDate->copy()->addDays($i);
            $info = $this->ovulationDayPhase($i, $day, $ovulationDay, $periodLength, 'calculation');
            $formattedDate = $day->format('Y-m-d');

            $phases[$formattedDate] = $info;
            $days[] = ['date' => $formattedDate] + $info;
        }

        $nextPeriodStart = $startDate->copy()->addDays($cycleLength);

        OvulationResult::create([
            'user_id' => $userId,
            'start_day_of_cycle' => $startDate,
            'cycle_length' => $cycleLength,
            'result' => [
                'period_length' => $periodLength,
                'ovulation_date' => $ovulationDay->toDateString(),
                'fertile_start' => $fertileStart->toDateString(),
                'fertile_end' => $fertileEnd->toDateString(),
                'next_period_start' => $nextPeriodStart->toDateString(),
                'cycle_phase_by_day' => $phases,
            ],
        ]);

        return [
            'message' => api_trans('health.messages.ovulation_calculated'),
            'data' => [
                'period' => [
                    'user_id' => $userId,
                    'start_day_of_cycle' => $startDate->toISOString(),
                    'cycle_length' => $cycleLength,
                    'period_length' => $periodLength,
                ],
                'ovulation_date' => $ovulationDay->toDateString(),
                'fertile_start' => $fertileStart->toDateString(),
                'fertile_end' => $fertileEnd->toDateString(),
                'next_period_start' => $nextPeriodStart->toDateString(),
                'days' => $days,
                'guideline' => $this->settings()->guideline('ovulation'),
                'disclaimer' => $this->settings()->disclaimer(api_locale()),
            ],
        ];
    }

    public function ovulationDay(Carbon $cycleStart, int $cycleLength): Carbon
    {
        return $cycleStart->copy()->addDays($cycleLength - $this->settings()->lutealPhaseDays());
    }

    /**
     * Phase and pregnancy chance of one day of a cycle. $dayIndex: day number from the start
     * of the cycle (0 = first day). $labels: "calculation" or "calendar" — the two endpoints have
     * always used slightly different Arabic wording in "phase".
     */
    public function ovulationDayPhase(int $dayIndex, Carbon $day, Carbon $ovulationDay, int $periodLength, string $labels = 'calculation'): array
    {
        // Negative = before ovulation, positive = after.
        $daysFromOvulation = (int) $ovulationDay->diffInDays($day, false);

        $code = match (true) {
            // Period days win even when they overlap the fertile window in short cycles.
            $dayIndex < $periodLength => 'menstruation',
            $daysFromOvulation === 0 => 'ovulation',
            in_array($daysFromOvulation, [-1, -2], true) => 'fertile_high',
            in_array($daysFromOvulation, [-3, -4], true) => 'fertile_medium',
            $daysFromOvulation === -5 => 'fertile_low',
            in_array($daysFromOvulation, [1, 2], true) => 'fertile_after',
            $daysFromOvulation > 2 => 'post_ovulation',
            default => 'not_fertile',
        };

        return [
            'phase' => __("health.legacy.ovulation_{$labels}.{$code}", [], 'ar'),
            'phase_code' => $code,
            'pregnancy_chance' => $this->settings()->phaseChance($code),
            'phase_label' => api_trans("health.phase.{$code}"),
        ];
    }

    // -------------------------------------------------------------------- BMI

    public function calculateBodyWeight($userId, array $data)
    {
        $heightMeters = $data['unit'] === 'metric' ? $data['height'] / 100 : $data['height'] * 0.0254;
        $weightKg = $data['unit'] === 'metric' ? $data['weight'] : $data['weight'] * 0.453592;
        $bmi = round($weightKg / ($heightMeters * $heightMeters), 1);

        // Category from the BMI result rules (default: WHO classification for adults).
        $result = $this->classification($this->evaluator->evaluate(HealthResultRule::BMI, $bmi), 'health.result_unknown');

        // Healthy range (Health settings; default BMI 18.5 to 24.9).
        [$idealMin, $idealMax] = $this->settings()->bmiIdealRange();
        $minIdealWeight = round($idealMin * ($heightMeters ** 2), 1);
        $maxIdealWeight = round($idealMax * ($heightMeters ** 2), 1);

        // status, description and ideal range are derived from bmi_result and not stored.
        BodyWeightResult::create([
            'user_id' => $userId,
            'height' => $data['height'],
            'weight' => $data['weight'],
            'unit' => $data['unit'],
            'bmi_result' => $bmi,
        ]);

        return [
            'message' => api_trans('health.messages.bmi_calculated'),
            'data' => [
                'bmi' => $bmi,
                'status' => $result['legacy'],
                'status_code' => $result['code'],
                'description' => $result['message_ar'],
                'ideal_range' => __('health.bmi.ideal_range', ['min' => $minIdealWeight, 'max' => $maxIdealWeight], 'ar'),
                'status_label' => $result['label'],
                'description_label' => $result['message'],
                'ideal_min_kg' => $minIdealWeight,
                'ideal_max_kg' => $maxIdealWeight,
                'note' => api_trans('health.bmi.adult_only'),
                'requires_urgent_care' => $result['urgent'],
                'guideline' => $this->settings()->guideline('bmi'),
                'disclaimer' => $this->settings()->disclaimer(api_locale()),
            ],
        ];
    }

    // ------------------------------------------------------------ blood sugar

    public function storeBloodSugar($userId, array $data)
    {
        $data['user_id'] = $userId;
        $record = BloodSugarMeasurement::create($data);

        $result = $this->classification($this->bloodSugarRule((float) $data['value'], (string) $data['condition_type']),
            'health.blood_sugar.unknown', 'health.legacy.blood_sugar.unknown');

        return [
            'message' => api_trans('health.messages.blood_sugar_saved'),
            'data' => [
                'value' => $record->value,
                'condition_type' => $record->condition_type,
                'measured_at' => $record->measured_at,
                'evaluation' => $result['legacy'],
                'evaluation_code' => $result['code'],
                'evaluation_label' => $result['label'],
                'condition_code' => BloodSugarCondition::fromInput((string) $data['condition_type'])?->value,
                'requires_urgent_care' => $result['urgent'],
                'advice' => $result['message'],
                'guideline' => $this->settings()->guideline('blood_sugar'),
                'disclaimer' => $this->settings()->disclaimer(api_locale()),
            ],
        ];
    }

    /**
     * mg/dL, classified by the blood sugar result rules (defaults, ADA Standards of Care: below 70
     * is low at any time; fasting normal is below 100; after a meal below 140; random below 200).
     * A reading whose condition is not recognised only matches rules for any condition.
     */
    public function bloodSugarRule(float $value, string $condition): ?HealthResultRule
    {
        return $this->evaluator->evaluate(HealthResultRule::BLOOD_SUGAR, $value, null, HealthEvaluator::sugarGroup($condition));
    }

    public function evaluateBloodSugar(float $value, string $condition): string
    {
        return $this->bloodSugarRule($value, $condition)?->code ?? 'unknown';
    }

    // --------------------------------------------------------- blood pressure

    public function storeBloodPressure($userId, array $data)
    {
        $data['user_id'] = $userId;
        $record = BloodPressureMeasurement::create($data);

        $result = $this->classification($this->bloodPressureRule((int) $data['systolic'], (int) $data['diastolic']), 'health.result_unknown');

        return [
            'message' => api_trans('health.messages.blood_pressure_saved'),
            'data' => [
                'systolic' => $record->systolic,
                'diastolic' => $record->diastolic,
                'condition_type' => $record->condition_type,
                'measured_at' => $record->measured_at,
                // The default crisis rule keeps the "stage 2" wording the app always received in
                // evaluation; requires_urgent_care and advice are what tell the user to act now.
                'evaluation' => $result['legacy'],
                'evaluation_code' => $result['code'],
                'evaluation_label' => $result['label'],
                'requires_urgent_care' => $result['urgent'],
                'advice' => $result['message'],
                'guideline' => $this->settings()->guideline('blood_pressure'),
                'disclaimer' => $this->settings()->disclaimer(api_locale()),
            ],
        ];
    }

    /**
     * mmHg, classified by the blood pressure result rules: a rule matches on the systolic OR the
     * diastolic range and the highest priority wins, i.e. the higher category of the two numbers
     * (defaults: 2017 ACC/AHA). The condition the reading was taken in does not change the result.
     */
    public function bloodPressureRule(int $systolic, int $diastolic): ?HealthResultRule
    {
        return $this->evaluator->evaluate(HealthResultRule::BLOOD_PRESSURE, $systolic, $diastolic);
    }

    public function evaluateBloodPressure(int $systolic, int $diastolic): string
    {
        return $this->bloodPressureRule($systolic, $diastolic)?->code ?? 'unknown';
    }

    // -------------------------------------------------------------- pregnancy

    public const PREGNANCY_DAYS = 280;
    public const PREGNANCY_MAX_COMPLETED_WEEKS = 42;

    public function calculatePregnancy($userId, array $data)
    {
        $lastPeriod = Carbon::parse($data['last_period_date'])->startOfDay();
        $result = $this->buildPregnancyResult($lastPeriod);

        // A message instead of a result (date in the future, or the pregnancy has ended).
        if (isset($result['message'])) {
            return $result;
        }

        return PregnancyCalculation::create([
            'user_id' => $userId,
            'last_period_date' => $lastPeriod,
            'due_date' => $result['due_date'],
            'result' => $result,
        ]);
    }

    public function recalculatePregnancyFromStoredData(PregnancyCalculation $pregnancy)
    {
        return $this->buildPregnancyResult($pregnancy->last_period_date->copy()->startOfDay());
    }

    /** Pregnancy month of a pregnancy week (protected arithmetic). month => first week. */
    private const MONTH_START_WEEKS = [1 => 1, 2 => 5, 3 => 9, 4 => 14, 5 => 19, 6 => 23, 7 => 28, 8 => 32, 9 => 36];

    private function buildPregnancyResult(Carbon $lastPeriod): array
    {
        // "Today" on the pharmacy's clock, not UTC, so the week does not lag a day after local midnight.
        $today = StoreClock::now()->startOfDay();

        if ($lastPeriod->greaterThan($today)) {
            return ['message' => api_trans('health.pregnancy.future_date'), 'code' => 'future_date'];
        }

        // Naegele's rule: first day of the last period + 280 days.
        $dueDate = $lastPeriod->copy()->addDays(self::PREGNANCY_DAYS);

        // Gestational age from the last period. Current week = completed weeks + 1.
        $diffInDays = (int) $lastPeriod->diffInDays($today);
        $completedWeeks = intdiv($diffInDays, 7);
        $currentWeek = $completedWeeks + 1;
        $currentDay = $diffInDays % 7;

        // From 42 completed weeks (post-term) we stop. 41 weeks is still a normal late pregnancy.
        if ($completedWeeks >= self::PREGNANCY_MAX_COMPLETED_WEEKS) {
            return ['message' => api_trans('health.pregnancy.ended'), 'code' => 'pregnancy_ended'];
        }

        $monthNumber = 1;
        foreach (self::MONTH_START_WEEKS as $month => $firstWeek) {
            if ($currentWeek >= $firstWeek) {
                $monthNumber = $month;
            }
        }
        $weekInMonth = $currentWeek - self::MONTH_START_WEEKS[$monthNumber] + 1;
        $dayInWeek = $currentDay + 1;

        // ACOG: first trimester to 13w6d, second to 27w6d.
        $trimester = $completedWeeks <= 13 ? 1 : ($completedWeeks <= 27 ? 2 : 3);

        // Informational content for this week (dashboard-managed).
        $content = PregnancyWeekContent::forWeek($currentWeek);
        $na = __('health.pregnancy.not_available', [], 'ar');
        $baby = [
            'size' => $content?->size_ar ?? $na,
            'weight' => $content?->weight_ar ?? $na,
            'length' => $content?->length_ar ?? $na,
        ];
        $locale = api_locale();

        return [
            'last_period_date' => $lastPeriod->toDateString(),
            'due_date' => $dueDate->toDateString(),
            'current_week' => $currentWeek,
            'current_day' => $currentDay,
            'month_info' => __('health.pregnancy.month_info', ['day' => $dayInWeek, 'week' => $weekInMonth, 'month' => $monthNumber], 'ar'),
            'baby_size' => $baby['size'],
            'baby_weight' => $baby['weight'],
            'baby_length' => $baby['length'],
            'image_stage' => $trimester,
            'trimester' => __("health.pregnancy.trimester.{$trimester}", [], 'ar'),
            'trimester_number' => $trimester,
            'summary' => __('health.pregnancy.summary', ['week' => $currentWeek, 'size' => $baby['size'], 'weight' => $baby['weight']], 'ar'),
            // Added fields.
            'month_number' => $monthNumber,
            'week_in_month' => $weekInMonth,
            'baby_size_label' => $content?->text('size', $locale) ?? api_trans('health.pregnancy.not_available'),
            'baby_weight_label' => $content?->text('weight', $locale) ?? api_trans('health.pregnancy.not_available'),
            'baby_length_label' => $content?->text('length', $locale) ?? api_trans('health.pregnancy.not_available'),
            'trimester_label' => api_trans("health.pregnancy.trimester.{$trimester}"),
            'tip' => $content?->text('tip', $locale),
            'image_url' => $content?->image ? asset($content->image) : null,
            'guideline' => $this->settings()->guideline('pregnancy'),
            'disclaimer' => $this->settings()->disclaimer($locale),
        ];
    }

    // ------------------------------------------------------------- vaccines

    /** Kept for callers of the old name (seeders): builds the child's schedule from the template. */
    public function generateDefaultVaccines(Child $child)
    {
        return $this->vaccines->generateFor($child);
    }
}
