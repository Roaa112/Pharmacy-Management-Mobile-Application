<?php

namespace Tests\Feature\Health;

use App\Models\PregnancyCalculation;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class HealthCalculationsTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        // 15:30 UTC = 18:30 in Baghdad, same calendar date.
        Carbon::setTestNow('2026-10-31 15:30:00');
        $this->customer = User::factory()->create();
        Sanctum::actingAs($this->customer);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function ovulation(string $start, int $cycle = 28, int $period = 5): array
    {
        return $this->postJson('/api/user/health/ovulation', [
            'start_day_of_cycle' => $start,
            'cycle_length' => $cycle,
            'period_length' => $period,
        ])->assertOk()->json('data');
    }

    private function calendarDays(): array
    {
        return collect($this->getJson('/api/user/health/ovulation/current')->assertOk()->json('months'))
            ->flatten(1)->keyBy('date')->all();
    }

    private function pregnancy(string $lastPeriod)
    {
        return $this->postJson('/api/user/health/calculatePregnancy', ['last_period_date' => $lastPeriod]);
    }

    // ---- Ovulation -------------------------------------------------------------------------

    public function test_every_day_of_a_28_day_cycle_has_the_right_phase_code_chance_and_label(): void
    {
        $data = $this->ovulation('2026-10-01');
        $days = collect($data['days'])->keyBy('date');

        $this->assertSame('2026-10-15', $data['ovulation_date']);
        $this->assertSame('2026-10-10', $data['fertile_start']);
        $this->assertSame('2026-10-17', $data['fertile_end']);
        $this->assertSame('2026-10-29', $data['next_period_start']);
        $this->assertCount(28, $days);

        $expected = [
            '2026-10-01' => ['menstruation', '0%', 'حيض'],
            '2026-10-05' => ['menstruation', '0%', 'حيض'],
            '2026-10-06' => ['not_fertile', '0%', 'غير مخصبة'],
            '2026-10-09' => ['not_fertile', '0%', 'غير مخصبة'],
            '2026-10-10' => ['fertile_low', '10%', 'خصوبة منخفضة'],
            '2026-10-11' => ['fertile_medium', '15%', 'خصوبة متوسطة'],
            '2026-10-12' => ['fertile_medium', '15%', 'خصوبة متوسطة'],
            '2026-10-13' => ['fertile_high', '25%', 'خصوبة عالية'],
            '2026-10-14' => ['fertile_high', '25%', 'خصوبة عالية'],
            '2026-10-15' => ['ovulation', '30%', 'تبويض'],
            '2026-10-16' => ['fertile_after', '5%', 'نهاية الخصوبة'],
            '2026-10-17' => ['fertile_after', '5%', 'نهاية الخصوبة'],
            '2026-10-18' => ['post_ovulation', '0%', 'ما بعد التبويض'],
            '2026-10-28' => ['post_ovulation', '0%', 'ما بعد التبويض'],
        ];

        foreach ($expected as $date => [$code, $chance, $label]) {
            $this->assertSame([$code, $chance, $label], [$days[$date]['phase_code'], $days[$date]['pregnancy_chance'], $days[$date]['phase']], $date);
        }
    }

    public function test_short_and_long_cycles_place_ovulation_14_days_before_the_next_period(): void
    {
        $long = collect($this->ovulation('2026-10-01', cycle: 35)['days'])->keyBy('date');
        $this->assertSame('ovulation', $long['2026-10-22']['phase_code']);
        $this->assertCount(35, $long);

        // 21-day cycle with a long period: the period wins where it overlaps the fertile window.
        $short = $this->ovulation('2026-10-01', cycle: 21, period: 10);
        $days = collect($short['days'])->keyBy('date');
        $this->assertSame('2026-10-08', $short['ovulation_date']);
        $this->assertSame('menstruation', $days['2026-10-08']['phase_code']);
        $this->assertSame('menstruation', $days['2026-10-10']['phase_code']);
        $this->assertSame('post_ovulation', $days['2026-10-11']['phase_code']);
    }

    public function test_calendar_and_calculation_agree_on_every_day_of_the_stored_cycle(): void
    {
        $calculated = collect($this->ovulation('2026-10-01')['days'])->keyBy('date');
        $calendar = $this->calendarDays();

        foreach ($calculated as $date => $day) {
            $this->assertSame($day['phase_code'], $calendar[$date]['phase_code'], $date);
            $this->assertSame($day['pregnancy_chance'], $calendar[$date]['pregnancy_chance'], $date);
        }

        // The calendar keeps the wording it always returned.
        $this->assertSame('غير خصبة', $calendar['2026-10-07']['phase']);
        $this->assertSame('خصوبة عالة', $calendar['2026-10-14']['phase']);
        $this->assertSame('بداية التبويض', $calendar['2026-10-16']['phase']);
    }

    public function test_calendar_cycle_boundaries_repeat_every_cycle_length(): void
    {
        $this->ovulation('2026-09-01', cycle: 30, period: 7);
        $days = $this->calendarDays();

        // Sep 1, Oct 1, Oct 31, Nov 30 start new cycles.
        $this->assertCount(30 + 31 + 30, $days);
        foreach (['2026-09-01', '2026-10-01', '2026-10-31', '2026-11-30'] as $start) {
            $this->assertSame('menstruation', $days[$start]['phase_code'], $start);
        }
        $this->assertSame('post_ovulation', $days['2026-09-30']['phase_code']);
        $this->assertSame('menstruation', $days['2026-10-07']['phase_code']);
        $this->assertSame('not_fertile', $days['2026-10-08']['phase_code']);
        $this->assertSame('ovulation', $days['2026-10-17']['phase_code']);
        $this->assertSame('ovulation', $days['2026-11-16']['phase_code']);

        $this->getJson('/api/user/health/ovulation/current')
            ->assertJsonPath('today.date', '2026-10-31')
            ->assertJsonPath('today.phase_code', 'menstruation')
            ->assertJsonPath('today.phase', 'حيض');
    }

    public function test_calendar_projects_backwards_when_the_stored_start_is_after_the_range_start(): void
    {
        $this->ovulation('2026-10-20');
        $days = $this->calendarDays();

        $this->assertCount(30 + 31 + 30, $days);
        $this->assertSame('menstruation', $days['2026-09-22']['phase_code']);
        $this->assertSame('ovulation', $days['2026-10-06']['phase_code']);
        $this->assertSame('menstruation', $days['2026-10-20']['phase_code']);
    }

    public function test_calendar_range_across_the_year_end_and_a_short_february(): void
    {
        Carbon::setTestNow('2026-12-31 12:00:00');
        $this->ovulation('2026-12-01');
        $days = $this->calendarDays();
        $this->assertSame('2026-11-01', array_key_first($days));
        $this->assertSame('2027-01-31', array_key_last($days));

        Carbon::setTestNow('2027-01-31 12:00:00');
        $days = $this->calendarDays();
        $this->assertSame('2026-12-01', array_key_first($days));
        $this->assertSame('2027-02-28', array_key_last($days));
        $this->assertCount(31 + 31 + 28, $days);
    }

    public function test_calendar_today_follows_the_pharmacy_clock_not_utc(): void
    {
        $this->ovulation('2026-10-01');

        // 22:30 UTC on Oct 31 is 01:30 on Nov 1 in Baghdad.
        Carbon::setTestNow('2026-10-31 22:30:00');

        $this->getJson('/api/user/health/ovulation/current')->assertJsonPath('today.date', '2026-11-01');
    }

    // ---- Pregnancy -------------------------------------------------------------------------

    public function test_pregnancy_week_and_day_transitions(): void
    {
        $cases = [
            // days since LMP => [current_week, current_day, trimester, trimester_number]
            0 => [1, 0, 'الثلث الأول', 1],
            6 => [1, 6, 'الثلث الأول', 1],
            7 => [2, 0, 'الثلث الأول', 1],
            97 => [14, 6, 'الثلث الأول', 1],   // 13w6d: last day of the first trimester
            98 => [15, 0, 'الثلث الثاني', 2],  // 14w0d
            195 => [28, 6, 'الثلث الثاني', 2], // 27w6d
            196 => [29, 0, 'الثلث الثالث', 3], // 28w0d
            293 => [42, 6, 'الثلث الثالث', 3], // 41w6d: still pregnant (late term)
        ];

        foreach ($cases as $days => [$week, $day, $trimester, $number]) {
            $lmp = Carbon::parse('2026-10-31')->subDays($days)->toDateString();
            $result = $this->pregnancy($lmp)->assertOk()->json('result');

            $this->assertSame([$week, $day, $trimester, $number], [$result['current_week'], $result['current_day'], $result['trimester'], $result['trimester_number']], "{$days} days");
            $this->assertSame(Carbon::parse($lmp)->addDays(280)->toDateString(), $result['due_date']);
        }
    }

    public function test_pregnancy_ends_at_42_completed_weeks(): void
    {
        $this->pregnancy(Carbon::parse('2026-10-31')->subDays(294)->toDateString())
            ->assertOk()
            ->assertJsonPath('code', 'pregnancy_ended')
            ->assertJsonStructure(['message'])
            ->assertJsonMissingPath('headers');

        $this->assertSame(0, PregnancyCalculation::count());
    }

    public function test_pregnancy_stored_and_current_stage_agree_and_advance_together(): void
    {
        $stored = $this->pregnancy('2026-09-01')->assertOk()->json('result');
        $this->assertSame([9, 4], [$stored['current_week'], $stored['current_day']]);
        $this->assertSame('2027-06-08', $stored['due_date']);

        $this->getJson('/api/user/health/CurrentPregnancyStage')
            ->assertJsonPath('current_week', 9)
            ->assertJsonPath('current_day', 4)
            ->assertJsonPath('month_info', $stored['month_info'])
            ->assertJsonPath('trimester_number', 1);

        // Three days later the stage moves into week 10, day 0.
        Carbon::setTestNow('2026-11-03 15:30:00');
        $this->getJson('/api/user/health/CurrentPregnancyStage')
            ->assertJsonPath('current_week', 10)
            ->assertJsonPath('current_day', 0);
    }

    public function test_pregnancy_dates_use_the_pharmacy_clock(): void
    {
        // 22:30 UTC on Oct 31 is already Nov 1 in Baghdad: Nov 1 is today, Nov 2 is the future.
        Carbon::setTestNow('2026-10-31 22:30:00');

        $this->pregnancy('2026-11-01')->assertOk()->assertJsonPath('result.current_week', 1)->assertJsonPath('result.current_day', 0);
        $this->pregnancy('2026-11-02')->assertUnprocessable();
    }

    public function test_pregnancy_baby_size_rows_cover_the_whole_pregnancy(): void
    {
        foreach ([30 => '1.3 كجم', 33 => '2.4 كجم', 40 => '3.5 كجم', 42 => '3.5 كجم'] as $week => $weight) {
            $lmp = Carbon::parse('2026-10-31')->subDays(($week - 1) * 7)->toDateString();
            $this->pregnancy($lmp)->assertOk()->assertJsonPath('result.baby_weight', $weight);
        }
    }

    // ---- Blood pressure --------------------------------------------------------------------

    public function test_blood_pressure_category_boundaries(): void
    {
        $cases = [
            [119, 79, 'normal', 'طبيعي'],
            [90, 60, 'normal', 'طبيعي'],
            [120, 79, 'elevated', 'غير منتظم - يحتاج قييم إضافي'],
            [129, 79, 'elevated', 'غير منتظم - يحتاج قييم إضافي'],
            [130, 79, 'stage_1', 'ضغط مرتفع (المرحلة الأولى)'],
            [119, 80, 'stage_1', 'ضغط مرتفع (المرحلة الأولى)'],
            [139, 89, 'stage_1', 'ضغط مرتفع (المرحلة الأولى)'],
            [140, 70, 'stage_2', 'ضغط مرتفع (المرحلة الثانية)'],
            [125, 90, 'stage_2', 'ضغط مرتفع (المرحلة الثانية)'],
            [180, 120, 'stage_2', 'ضغط مرتفع (المرحلة الثانية)'],
            [181, 100, 'hypertensive_crisis', 'ضغط مرتفع (المرحلة الثانية)'],
            [150, 121, 'hypertensive_crisis', 'ضغط مرتفع (المرحلة الثانية)'],
            [89, 70, 'low', 'ضغط منخفض'],
            [100, 59, 'low', 'ضغط منخفض'],
            [85, 55, 'low', 'ضغط منخفض'],
            // The higher category of the two numbers wins.
            [135, 55, 'stage_1', 'ضغط مرتفع (المرحلة الأولى)'],
        ];

        foreach ($cases as [$systolic, $diastolic, $code, $label]) {
            $this->postJson('/api/user/health/blood-pressure', [
                'systolic' => $systolic,
                'diastolic' => $diastolic,
                'condition_type' => 'random',
                'measured_at' => '2026-10-31 10:00',
            ])->assertOk()
                ->assertJsonPath('data.evaluation_code', $code, "{$systolic}/{$diastolic}")
                ->assertJsonPath('data.evaluation', $label);
        }
    }

    public function test_blood_pressure_is_classified_as_measured_whatever_the_condition(): void
    {
        foreach (['بعد الأكل', 'after_meal', 'random', 'صائم'] as $condition) {
            $this->postJson('/api/user/health/blood-pressure', [
                'systolic' => 134, 'diastolic' => 84, 'condition_type' => $condition, 'measured_at' => '2026-10-31 10:00',
            ])->assertOk()->assertJsonPath('data.evaluation_code', 'stage_1');
        }
    }

    // ---- Blood sugar -----------------------------------------------------------------------

    public function test_blood_sugar_boundaries_for_each_condition(): void
    {
        $cases = [
            [69, 'fasting', 'low'], [70, 'fasting', 'normal'], [99, 'fasting', 'normal'],
            [99.5, 'fasting', 'normal'], [100, 'fasting', 'high'], [126, 'fasting', 'high'],
            [99, 'before_breakfast', 'normal'], [100, 'before_meal', 'high'], [105, 'صائم', 'high'],
            [69, 'after_meal', 'low'], [139, 'after_meal', 'normal'], [140, 'after_meal', 'high'], [139, 'بعد الأكل', 'normal'],
            [69, 'random', 'low'], [199, 'random', 'normal'], [200, 'random', 'high'],
            // Unknown condition: only a low value can be judged.
            [120, 'bedtime', 'unknown'], [60, 'bedtime', 'low'],
        ];
        $labels = ['low' => 'منخفض', 'normal' => 'طبيعي', 'high' => 'مرتفع', 'unknown' => 'غير معروف'];

        foreach ($cases as [$value, $condition, $code]) {
            $this->postJson('/api/user/health/blood-sugar', [
                'value' => $value,
                'condition_type' => $condition,
                'measured_at' => '2026-10-31 10:00',
            ])->assertOk()
                ->assertJsonPath('data.evaluation_code', $code, "{$value} {$condition}")
                ->assertJsonPath('data.evaluation', $labels[$code]);
        }
    }

    // ---- BMI -------------------------------------------------------------------------------

    public function test_bmi_category_boundaries(): void
    {
        // With a 100 cm height the BMI equals the weight in kg.
        $cases = [
            [18.4, 'underweight'], [18.5, 'normal'], [24.9, 'normal'], [25, 'overweight'],
            [29.9, 'overweight'], [30, 'obese_class_1'], [34.9, 'obese_class_1'], [35, 'obese_class_2'],
            [39.9, 'obese_class_2'], [40, 'obese_class_3'],
        ];

        foreach ($cases as [$weight, $code]) {
            $this->postJson('/api/user/health/body-weight', ['height' => 100, 'weight' => $weight, 'unit' => 'metric'])
                ->assertOk()
                ->assertJsonPath('data.status_code', $code, (string) $weight);
        }
    }

    public function test_bmi_metric_and_imperial(): void
    {
        $this->postJson('/api/user/health/body-weight', ['height' => 170, 'weight' => 70, 'unit' => 'metric'])
            ->assertOk()
            ->assertJsonPath('data.bmi', 24.2)
            ->assertJsonPath('data.status', 'وزن طبيعي')
            ->assertJsonPath('data.status_code', 'normal');

        // 67 in / 154 lb ≈ 1.70 m / 69.9 kg
        $this->postJson('/api/user/health/body-weight', ['height' => 67, 'weight' => 154, 'unit' => 'imperial'])
            ->assertOk()
            ->assertJsonPath('data.bmi', 24.1);
    }
}
