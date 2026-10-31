<?php

namespace Tests\Feature\Api;

use App\Models\BloodPressureMeasurement;
use App\Models\BloodSugarMeasurement;
use App\Models\BodyWeightResult;
use App\Models\Child;
use App\Models\User;
use App\Models\Vaccine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class HealthDataApiTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;
    private User $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customer = User::factory()->create();
        $this->other = User::factory()->create();
        Sanctum::actingAs($this->customer);
    }

    public function test_measurement_history_contains_only_the_callers_records(): void
    {
        foreach ([$this->customer, $this->other] as $user) {
            BloodPressureMeasurement::create(['user_id' => $user->id, 'systolic' => 120, 'diastolic' => 80, 'condition_type' => 'random', 'measured_at' => now()]);
            BloodSugarMeasurement::create(['user_id' => $user->id, 'value' => 90, 'condition_type' => 'fasting', 'measured_at' => now()]);
            BodyWeightResult::create(['user_id' => $user->id, 'height' => 170, 'weight' => 70, 'unit' => 'metric', 'bmi_result' => 24.2]);
        }

        foreach (['/api/user/health/blood-pressure', '/api/user/health/blood-sugar', '/api/user/health/weight'] as $url) {
            $data = $this->getJson($url)->assertOk()->json('data');
            $this->assertCount(1, $data, $url);
            $this->assertSame($this->customer->id, $data[0]['user_id'], $url);
        }
    }

    public function test_new_measurements_are_stored_for_the_caller_only(): void
    {
        $this->postJson('/api/user/health/blood-sugar', ['value' => 90, 'condition_type' => 'fasting', 'measured_at' => '2026-10-31 08:00', 'user_id' => $this->other->id])->assertOk();
        $this->postJson('/api/user/health/blood-pressure', ['systolic' => 120, 'diastolic' => 80, 'condition_type' => 'random', 'measured_at' => '2026-10-31 08:00', 'user_id' => $this->other->id])->assertOk();

        $this->assertSame(0, BloodSugarMeasurement::where('user_id', $this->other->id)->count());
        $this->assertSame(0, BloodPressureMeasurement::where('user_id', $this->other->id)->count());
    }

    public function test_measurement_input_is_validated(): void
    {
        $this->postJson('/api/user/health/blood-sugar', ['value' => -1, 'condition_type' => 'fasting', 'measured_at' => '2026-10-31'])->assertStatus(422);
        $this->postJson('/api/user/health/blood-sugar', ['value' => 90, 'condition_type' => 'fasting', 'measured_at' => 'yesterday-ish'])->assertStatus(422);
        $this->postJson('/api/user/health/blood-pressure', ['systolic' => 0, 'diastolic' => 80, 'condition_type' => 'random', 'measured_at' => '2026-10-31'])->assertStatus(422);
        $this->postJson('/api/user/health/body-weight', ['height' => 0, 'weight' => 70, 'unit' => 'metric'])->assertStatus(422);
        $this->postJson('/api/user/health/ovulation', ['start_day_of_cycle' => '2026-10-01', 'cycle_length' => 50, 'period_length' => 5])->assertStatus(422);
        $this->postJson('/api/user/health/calculatePregnancy', [])->assertStatus(422);

        $this->assertSame(0, BloodSugarMeasurement::count() + BloodPressureMeasurement::count() + BodyWeightResult::count());
    }

    public function test_adding_a_child_creates_the_vaccine_schedule(): void
    {
        $child = $this->postJson('/api/user/health/child', ['child_name' => 'Ali', 'gender' => 'ذكر', 'birth_date' => '2026-01-15', 'user_id' => $this->other->id])
            ->assertOk()
            ->json('child');

        $this->assertSame($this->customer->id, $child['user_id']);
        $this->assertCount(9, $child['vaccines']);
        $this->assertSame('2026-03-15', substr(collect($child['vaccines'])->firstWhere('vaccine_name', 'لقاح عمر شهرين')['scheduled_date'], 0, 10));

        $this->postJson('/api/user/health/child', ['child_name' => 'Ali', 'gender' => 'other', 'birth_date' => '2026-01-15'])->assertStatus(422);
        $this->postJson('/api/user/health/child', ['gender' => 'ذكر', 'birth_date' => '2026-01-15'])->assertStatus(422);
    }

    public function test_vaccine_list_progress_and_completion(): void
    {
        $id = $this->postJson('/api/user/health/child', ['child_name' => 'Ali', 'gender' => 'ذكر', 'birth_date' => '2026-01-15'])->json('child.id');
        $theirs = Child::create(['user_id' => $this->other->id, 'child_name' => 'Other', 'gender' => 'أنثى', 'birth_date' => '2026-01-01']);

        $vaccine = Vaccine::where('child_id', $id)->orderBy('scheduled_date')->first();
        $this->patchJson("/api/user/health/vaccines/{$vaccine->id}/complete")->assertOk();
        $this->assertTrue((bool) $vaccine->fresh()->is_completed);

        $this->patchJson("/api/user/health/vaccines/{$vaccine->id}/complete", ['is_completed' => false])->assertOk();
        $this->assertFalse((bool) $vaccine->fresh()->is_completed);
        $this->patchJson("/api/user/health/vaccines/{$vaccine->id}/complete", ['is_completed' => 'maybe'])->assertStatus(422);
        $this->patchJson("/api/user/health/vaccines/{$vaccine->id}/complete")->assertOk();

        $children = $this->getJson('/api/user/health/child/vaccines')->assertOk()->json('children');
        $this->assertSame([$id], array_column($children, 'id'));
        $this->assertEquals(11.11, $children[0]['progress']);
        $this->assertNotContains($theirs->id, array_column($children, 'id'));
    }

    public function test_another_users_child_and_vaccines_cannot_be_changed(): void
    {
        $theirs = Child::create(['user_id' => $this->other->id, 'child_name' => 'Other', 'gender' => 'أنثى', 'birth_date' => '2026-01-01']);
        $vaccine = Vaccine::create(['child_id' => $theirs->id, 'vaccine_name' => 'BCG', 'scheduled_date' => '2026-01-08', 'is_completed' => false]);

        $this->patchJson("/api/user/health/vaccines/{$vaccine->id}/complete")->assertNotFound();
        $this->deleteJson("/api/user/health/child/{$theirs->id}")->assertNotFound();

        $this->assertFalse((bool) $vaccine->fresh()->is_completed);
        $this->assertNotNull($theirs->fresh());
    }

    public function test_child_can_be_deleted_with_its_vaccines(): void
    {
        $id = $this->postJson('/api/user/health/child', ['child_name' => 'Ali', 'gender' => 'ذكر', 'birth_date' => '2026-01-15'])->json('child.id');

        $this->deleteJson("/api/user/health/child/{$id}")->assertOk();

        $this->assertNull(Child::find($id));
        $this->assertSame(0, Vaccine::where('child_id', $id)->count());
    }

    public function test_cycle_calendar_without_data_asks_for_the_first_entry(): void
    {
        $this->getJson('/api/user/health/ovulation/current')->assertOk()->assertJsonStructure(['message']);
        $this->getJson('/api/user/health/CurrentPregnancyStage')->assertNotFound();
    }
}
