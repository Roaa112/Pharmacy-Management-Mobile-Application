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

        Carbon::setTestNow('2026-10-31 15:30:00');
        $this->customer = User::factory()->create();
        Sanctum::actingAs($this->customer);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_ovulation_fertility_peaks_before_ovulation_day(): void
    {
        $days = $this->postJson('/api/user/health/ovulation', [
            'start_day_of_cycle' => '2026-10-01',
            'cycle_length' => 28,
            'period_length' => 5,
        ])->assertOk()->json('data.days');

        $byDate = collect($days)->keyBy('date');

        $this->assertSame('2026-10-15', collect($days)->firstWhere('phase', 'تبويض')['date']);
        $this->assertSame('حيض', $byDate['2026-10-05']['phase']);
        $this->assertSame('10%', $byDate['2026-10-10']['pregnancy_chance']); // -5
        $this->assertSame('15%', $byDate['2026-10-11']['pregnancy_chance']); // -4
        $this->assertSame('25%', $byDate['2026-10-13']['pregnancy_chance']); // -2
        $this->assertSame('25%', $byDate['2026-10-14']['pregnancy_chance']); // -1
        $this->assertSame('30%', $byDate['2026-10-15']['pregnancy_chance']); // ovulation
        $this->assertSame('15%', $byDate['2026-10-16']['pregnancy_chance']); // +1
        $this->assertSame('10%', $byDate['2026-10-17']['pregnancy_chance']); // +2
        $this->assertSame('ما بعد التبويض', $byDate['2026-10-18']['phase']);
        $this->assertSame('0%', $byDate['2026-10-07']['pregnancy_chance']);
    }

    public function test_current_month_cycle_projects_forward_from_stored_start(): void
    {
        $this->postJson('/api/user/health/ovulation', [
            'start_day_of_cycle' => '2026-09-01',
            'cycle_length' => 30,
            'period_length' => 7,
        ])->assertOk();

        $response = $this->getJson('/api/user/health/ovulation/current')->assertOk();
        $days = collect($response->json('months'))->flatten(1)->keyBy('date');

        // Every day of Sep, Oct and Nov is present exactly once.
        $this->assertCount(30 + 31 + 30, $days);

        // Cycles start Sep 1, Oct 1, Oct 31 — the stored 7-day period is used.
        $this->assertSame('حيض', $days['2026-10-07']['phase']);
        $this->assertNotSame('حيض', $days['2026-10-08']['phase']);
        $this->assertSame('حيض', $days['2026-10-31']['phase']);
        $this->assertSame('تبويض', $days['2026-10-17']['phase']);
        $this->assertSame('تبويض', $days['2026-11-16']['phase']);

        $response->assertJsonPath('today.date', '2026-10-31')
            ->assertJsonPath('today.phase', 'حيض');
    }

    public function test_pregnancy_week_matches_between_store_and_current_stage(): void
    {
        // 60 days ago => 8 full weeks + 4 days => week 9.
        $stored = $this->postJson('/api/user/health/calculatePregnancy', ['last_period_date' => '2026-09-01'])
            ->assertOk()
            ->json('result');

        $this->assertSame(9, $stored['current_week']);
        $this->assertSame(4, $stored['current_day']);
        $this->assertSame('2027-06-08', $stored['due_date']);
        $this->assertSame('الثلث الأول', $stored['trimester']);

        $this->getJson('/api/user/health/CurrentPregnancyStage')
            ->assertOk()
            ->assertJsonPath('current_week', 9)
            ->assertJsonPath('current_day', 4);
    }

    public function test_pregnancy_rejects_future_date_and_reports_ended_pregnancy(): void
    {
        $this->postJson('/api/user/health/calculatePregnancy', ['last_period_date' => '2026-11-05'])
            ->assertUnprocessable();

        $this->postJson('/api/user/health/calculatePregnancy', ['last_period_date' => '2025-12-01'])
            ->assertOk()
            ->assertJsonStructure(['message'])
            ->assertJsonMissingPath('headers');

        $this->assertSame(0, PregnancyCalculation::count());
    }

    public function test_blood_pressure_evaluation(): void
    {
        $cases = [
            [118, 78, 'طبيعي'],
            [125, 78, 'ضغط مرتفع قليلاً'],
            [132, 78, 'ضغط مرتفع (المرحلة الأولى)'],
            [142, 85, 'ضغط مرتفع (المرحلة الثانية)'],
            [85, 70, 'ضغط منخفض'],
        ];

        foreach ($cases as [$systolic, $diastolic, $expected]) {
            $this->postJson('/api/user/health/blood-pressure', [
                'systolic' => $systolic,
                'diastolic' => $diastolic,
                'condition_type' => 'random',
                'measured_at' => '2026-10-31 10:00',
            ])->assertOk()->assertJsonPath('data.evaluation', $expected);
        }

        // The after-meal margin applies to systolic too: 134 → 129.
        $this->postJson('/api/user/health/blood-pressure', [
            'systolic' => 134, 'diastolic' => 70, 'condition_type' => 'بعد الأكل', 'measured_at' => '2026-10-31 10:00',
        ])->assertOk()->assertJsonPath('data.evaluation', 'ضغط مرتفع قليلاً');
    }

    public function test_blood_sugar_evaluation(): void
    {
        $cases = [
            [90, 'fasting', 'طبيعي'],
            [110, 'fasting', 'مرتفع'],
            [60, 'fasting', 'منخفض'],
            [130, 'after_meal', 'طبيعي'],
            [150, 'after_meal', 'مرتفع'],
            [55, 'after_meal', 'منخفض'],
            [55, 'random', 'منخفض'],
        ];

        foreach ($cases as [$value, $condition, $expected]) {
            $this->postJson('/api/user/health/blood-sugar', [
                'value' => $value,
                'condition_type' => $condition,
                'measured_at' => '2026-10-31 10:00',
            ])->assertOk()->assertJsonPath('data.evaluation', $expected);
        }
    }

    public function test_bmi_metric_and_imperial(): void
    {
        $this->postJson('/api/user/health/body-weight', ['height' => 170, 'weight' => 70, 'unit' => 'metric'])
            ->assertOk()
            ->assertJsonPath('data.bmi', 24.2)
            ->assertJsonPath('data.status', 'وزن طبيعي');

        // 67 in / 154 lb ≈ 1.70 m / 69.9 kg
        $this->postJson('/api/user/health/body-weight', ['height' => 67, 'weight' => 154, 'unit' => 'imperial'])
            ->assertOk()
            ->assertJsonPath('data.bmi', 24.1);
    }
}
