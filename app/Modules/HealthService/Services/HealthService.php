<?php

namespace App\Modules\HealthService\Services;

use App\Enums\BloodSugarCondition;
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
 * The medical rules (thresholds, formulas, cycle and pregnancy arithmetic) are protected code:
 * they follow published guidelines, are covered by boundary tests and change only through code
 * review. GUIDELINES says which version each follows and is returned with every result.
 *
 * Display text comes from lang/{ar,en}/health.php. Fields older apps may compare against
 * (phase, status, evaluation, trimester...) keep their exact Arabic wording ("legacy"); the
 * *_code fields are stable identifiers and the *_label fields follow Accept-Language.
 *
 * Content that is information rather than calculation (vaccination schedule, pregnancy week
 * content) is managed from the dashboard.
 */
class HealthService
{
    public const GUIDELINES = [
        'bmi' => 'WHO adult BMI classification',
        'blood_sugar' => 'ADA Standards of Care (mg/dL)',
        'blood_pressure' => '2017 ACC/AHA',
        'ovulation' => 'Fixed 14-day luteal phase; fertile window per Wilcox et al. 1995',
        'pregnancy' => 'Naegele rule (LMP + 280 days); ACOG trimesters',
    ];

    public function __construct(private VaccineScheduleService $vaccines)
    {
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

    /**
     * Chance of pregnancy per cycle phase. Ovulation is cycle_length - 14 (14-day luteal phase);
     * the fertile window is the 5 days before ovulation, ovulation day and 2 days after.
     * Values follow the shape of Wilcox et al. (NEJM 1995): highest on the 2 days before and on
     * ovulation day, and close to zero once the egg is gone (1-2 days after).
     */
    public const OVULATION_PHASE_CHANCES = [
        'menstruation' => '0%',
        'not_fertile' => '0%',
        'fertile_low' => '10%',     // 5 days before ovulation
        'fertile_medium' => '15%',  // 4-3 days before
        'fertile_high' => '25%',    // 2-1 days before
        'ovulation' => '30%',
        'fertile_after' => '5%',    // 1-2 days after
        'post_ovulation' => '0%',
    ];

    public const LUTEAL_PHASE_DAYS = 14;

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
                'guideline' => self::GUIDELINES['ovulation'],
            ],
        ];
    }

    public function ovulationDay(Carbon $cycleStart, int $cycleLength): Carbon
    {
        return $cycleStart->copy()->addDays($cycleLength - self::LUTEAL_PHASE_DAYS);
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
            'pregnancy_chance' => self::OVULATION_PHASE_CHANCES[$code],
            'phase_label' => api_trans("health.phase.{$code}"),
        ];
    }

    // -------------------------------------------------------------------- BMI

    public function calculateBodyWeight($userId, array $data)
    {
        $heightMeters = $data['unit'] === 'metric' ? $data['height'] / 100 : $data['height'] * 0.0254;
        $weightKg = $data['unit'] === 'metric' ? $data['weight'] : $data['weight'] * 0.453592;
        $bmi = round($weightKg / ($heightMeters * $heightMeters), 1);

        // WHO classification for adults.
        $statusCode = match (true) {
            $bmi < 18.5 => 'underweight',
            $bmi < 25 => 'normal',
            $bmi < 30 => 'overweight',
            $bmi < 35 => 'obese_class_1',
            $bmi < 40 => 'obese_class_2',
            default => 'obese_class_3',
        };

        // Healthy range: BMI 18.5 to 24.9.
        $minIdealWeight = round(18.5 * ($heightMeters ** 2), 1);
        $maxIdealWeight = round(24.9 * ($heightMeters ** 2), 1);

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
                'status' => __("health.bmi.{$statusCode}.status", [], 'ar'),
                'status_code' => $statusCode,
                'description' => __("health.bmi.{$statusCode}.description", [], 'ar'),
                'ideal_range' => __('health.bmi.ideal_range', ['min' => $minIdealWeight, 'max' => $maxIdealWeight], 'ar'),
                'status_label' => api_trans("health.bmi.{$statusCode}.status"),
                'description_label' => api_trans("health.bmi.{$statusCode}.description"),
                'ideal_min_kg' => $minIdealWeight,
                'ideal_max_kg' => $maxIdealWeight,
                'note' => api_trans('health.bmi.adult_only'),
                'guideline' => self::GUIDELINES['bmi'],
            ],
        ];
    }

    // ------------------------------------------------------------ blood sugar

    public function storeBloodSugar($userId, array $data)
    {
        $data['user_id'] = $userId;
        $record = BloodSugarMeasurement::create($data);

        $code = $this->evaluateBloodSugar((float) $data['value'], (string) $data['condition_type']);

        return [
            'message' => api_trans('health.messages.blood_sugar_saved'),
            'data' => [
                'value' => $record->value,
                'condition_type' => $record->condition_type,
                'measured_at' => $record->measured_at,
                'evaluation' => __("health.legacy.blood_sugar.{$code}", [], 'ar'),
                'evaluation_code' => $code,
                'evaluation_label' => api_trans("health.blood_sugar.{$code}"),
                'condition_code' => BloodSugarCondition::fromInput((string) $data['condition_type'])?->value,
                'guideline' => self::GUIDELINES['blood_sugar'],
            ],
        ];
    }

    /**
     * mg/dL, ADA Standards of Care: below 70 is hypoglycaemia at any time; fasting normal is
     * below 100; after a meal normal is below 140; a random value of 200+ is high.
     */
    public function evaluateBloodSugar(float $value, string $condition): string
    {
        $condition = BloodSugarCondition::fromInput($condition);

        return match (true) {
            $value < 70 => 'low',
            $condition === null => 'unknown',
            $condition->isFastingLike() => $value < 100 ? 'normal' : 'high',
            $condition === BloodSugarCondition::AfterMeal => $value < 140 ? 'normal' : 'high',
            default => $value < 200 ? 'normal' : 'high', // random
        };
    }

    // --------------------------------------------------------- blood pressure

    public function storeBloodPressure($userId, array $data)
    {
        $data['user_id'] = $userId;
        $record = BloodPressureMeasurement::create($data);

        $code = $this->evaluateBloodPressure((int) $data['systolic'], (int) $data['diastolic']);
        $crisis = $code === 'hypertensive_crisis';

        return [
            'message' => api_trans('health.messages.blood_pressure_saved'),
            'data' => [
                'systolic' => $record->systolic,
                'diastolic' => $record->diastolic,
                'condition_type' => $record->condition_type,
                'measured_at' => $record->measured_at,
                // A crisis keeps the "stage 2" wording the app always received in evaluation;
                // requires_urgent_care and advice are what tell the user to act now.
                'evaluation' => __("health.legacy.blood_pressure.{$code}", [], 'ar'),
                'evaluation_code' => $code,
                'evaluation_label' => api_trans("health.blood_pressure.{$code}"),
                'requires_urgent_care' => $crisis,
                'advice' => $crisis ? api_trans('health.blood_pressure.crisis_advice') : null,
                'guideline' => self::GUIDELINES['blood_pressure'],
            ],
        ];
    }

    /**
     * mmHg, 2017 ACC/AHA categories; the higher category of the two numbers wins. Low is below
     * 90 systolic or below 60 diastolic. Readings are classified as measured: the guideline has
     * no allowance for meals, so condition_type does not change the result.
     */
    public function evaluateBloodPressure(int $systolic, int $diastolic): string
    {
        return match (true) {
            $systolic > 180 || $diastolic > 120 => 'hypertensive_crisis',
            $systolic >= 140 || $diastolic >= 90 => 'stage_2',
            $systolic >= 130 || $diastolic >= 80 => 'stage_1',
            $systolic < 90 || $diastolic < 60 => 'low',
            $systolic >= 120 => 'elevated',
            default => 'normal',
        };
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
            'guideline' => self::GUIDELINES['pregnancy'],
        ];
    }

    // ------------------------------------------------------------- vaccines

    /** Kept for callers of the old name (seeders): builds the child's schedule from the template. */
    public function generateDefaultVaccines(Child $child)
    {
        return $this->vaccines->generateFor($child);
    }
}
