<?php

namespace Tests\Feature\Api;

use App\Models\Medication;
use App\Models\MedicationLog;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MedicationApiTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-31 09:00:00'); // a Saturday
        $this->customer = User::factory()->create();
        Sanctum::actingAs($this->customer);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function createMedication(array $overrides = []): int
    {
        return $this->postJson('/api/user/medication', array_merge([
            'name' => 'Vitamin D',
            'quantity_per_time' => 1,
            'repeat_type' => 'specific_days',
            'times' => ['08:00:00', '20:00:00'],
            'days' => ['saturday', 'monday'],
        ], $overrides))->assertCreated()->json('data.id');
    }

    private function day(array $week, string $day): array
    {
        return collect($week)->firstWhere('day', $day)['medications'];
    }

    public function test_medication_is_created_with_times_and_days(): void
    {
        $id = $this->createMedication();

        $medication = Medication::with('times', 'days')->findOrFail($id);
        $this->assertSame($this->customer->id, $medication->user_id);
        $this->assertCount(2, $medication->times);
        $this->assertEqualsCanonicalizing(['saturday', 'monday'], $medication->days->pluck('day')->all());
    }

    public function test_medication_input_is_validated(): void
    {
        $base = ['name' => 'Vitamin D', 'quantity_per_time' => 1, 'repeat_type' => 'every_day', 'times' => ['08:00']];

        $this->postJson('/api/user/medication', array_merge($base, ['times' => []]))->assertStatus(422)->assertJsonValidationErrors('times');
        $this->postJson('/api/user/medication', array_merge($base, ['times' => ['not a time']]))->assertStatus(422)->assertJsonValidationErrors('times.0');
        $this->postJson('/api/user/medication', array_merge($base, ['repeat_type' => 'hourly']))->assertStatus(422);
        $this->postJson('/api/user/medication', array_merge($base, ['quantity_per_time' => 0]))->assertStatus(422);
        $this->postJson('/api/user/medication', array_merge($base, ['days' => ['funday']]))->assertStatus(422);
        $this->assertSame(0, Medication::count());

        // Both HH:MM and HH:MM:SS are accepted.
        $this->postJson('/api/user/medication', $base)->assertCreated();
    }

    public function test_weekly_schedule_shows_each_medication_on_its_days(): void
    {
        $this->createMedication(['name' => 'Specific']);
        $this->createMedication(['name' => 'Daily', 'repeat_type' => 'every_day', 'days' => null, 'times' => ['09:00:00']]);

        $week = $this->getJson('/api/user/medication')->assertOk()->json();

        $this->assertSame(['saturday', 'sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday'], array_column($week, 'day'));
        $this->assertEqualsCanonicalizing(['Specific', 'Daily'], array_column($this->day($week, 'saturday'), 'name'));
        $this->assertSame(['Daily'], array_column($this->day($week, 'sunday'), 'name'));

        $daily = collect($this->day($week, 'sunday'))->firstWhere('name', 'Daily');
        $this->assertCount(7, $daily['days']);
        $this->assertSame('pending', $daily['times'][0]['status']);
    }

    public function test_logging_a_dose_marks_it_on_that_date_only(): void
    {
        $id = $this->createMedication(['repeat_type' => 'every_day', 'days' => null]);

        $this->postJson("/api/user/medications/{$id}/log", ['time' => '08:00:00', 'status' => 'taken'])->assertOk();
        // Logging again updates the same row instead of creating a duplicate.
        $this->postJson("/api/user/medications/{$id}/log", ['time' => '08:00:00', 'status' => 'missed'])->assertOk();
        $this->postJson("/api/user/medications/{$id}/log", ['time' => '08:00:00', 'status' => 'taken'])->assertOk();
        $this->assertSame(1, MedicationLog::count());

        $week = $this->getJson('/api/user/medication')->json();

        $saturday = $this->day($week, 'saturday')[0]['times'];
        $sunday = $this->day($week, 'sunday')[0]['times'];
        $this->assertSame(['taken', 'pending'], array_column($saturday, 'status'));
        $this->assertSame(['pending', 'pending'], array_column($sunday, 'status'));
    }

    public function test_dose_log_is_validated_and_future_dates_are_refused(): void
    {
        $id = $this->createMedication();

        $this->postJson("/api/user/medications/{$id}/log", ['time' => '08:00', 'status' => 'taken'])->assertStatus(422);
        $this->postJson("/api/user/medications/{$id}/log", ['time' => '08:00:00', 'status' => 'eaten'])->assertStatus(422);
        $this->postJson("/api/user/medications/{$id}/log", ['time' => '08:00:00', 'status' => 'taken', 'shown_date' => '2026-11-01'])
            ->assertStatus(422);

        $this->assertSame(0, MedicationLog::count());
    }

    public function test_medication_can_be_updated(): void
    {
        $id = $this->createMedication();

        $this->postJson("/api/user/medications/{$id}/update", [
            'name' => 'Iron',
            'quantity_per_time' => 2,
            'repeat_type' => 'once',
            'times' => ['10:00'],
            'days' => ['tuesday'],
        ])->assertOk()->assertJsonPath('name', 'Iron');

        $medication = Medication::with('times', 'days')->findOrFail($id);
        $this->assertCount(1, $medication->times);
        $this->assertSame(['tuesday'], $medication->days->pluck('day')->all());

        // A failed update must not wipe the existing schedule.
        $this->postJson("/api/user/medications/{$id}/update", [
            'name' => 'Iron', 'quantity_per_time' => 2, 'repeat_type' => 'once', 'times' => ['bad'], 'days' => ['tuesday'],
        ])->assertStatus(422);
        $this->assertCount(1, $medication->fresh()->times);
    }

    public function test_removing_a_day(): void
    {
        $daily = $this->createMedication(['repeat_type' => 'every_day', 'days' => null]);
        $specific = $this->createMedication();
        $once = $this->createMedication(['repeat_type' => 'once', 'days' => ['monday']]);

        $this->deleteJson('/api/user/medications/remove-day', ['medication_id' => $daily, 'day' => 'friday'])->assertOk();
        $medication = Medication::with('days')->find($daily);
        $this->assertSame('specific_days', $medication->repeat_type);
        $this->assertCount(6, $medication->days);
        $this->assertNotContains('friday', $medication->days->pluck('day'));

        $this->deleteJson('/api/user/medications/remove-day', ['medication_id' => $specific, 'day' => 'monday'])->assertOk();
        $this->deleteJson('/api/user/medications/remove-day', ['medication_id' => $specific, 'day' => 'monday'])->assertNotFound();

        $this->deleteJson('/api/user/medications/remove-day', ['medication_id' => $once, 'day' => 'monday'])->assertOk();
        $this->assertNull(Medication::find($once));

        $this->deleteJson('/api/user/medications/remove-day', ['medication_id' => $specific, 'day' => 'someday'])->assertStatus(422);
    }

    public function test_medication_can_be_deleted(): void
    {
        $id = $this->createMedication();

        $this->deleteJson("/api/user/medication/{$id}")->assertOk();

        $this->assertNull(Medication::find($id));
        $this->assertSame(0, DB::table('medication_times')->count());
    }

    public function test_another_users_medication_cannot_be_read_changed_logged_or_deleted(): void
    {
        $id = $this->createMedication();
        Sanctum::actingAs(User::factory()->create());

        $this->assertSame([], collect($this->getJson('/api/user/medication')->json())->pluck('medications')->flatten(1)->all());

        $payload = ['name' => 'Hacked', 'quantity_per_time' => 1, 'repeat_type' => 'every_day', 'times' => ['08:00']];
        $this->postJson("/api/user/medications/{$id}/update", $payload)->assertNotFound();
        $this->putJson("/api/user/{$id}", $payload)->assertNotFound();
        // Ownership is checked before validation, so an invalid payload is a 404 too.
        $this->postJson("/api/user/medications/{$id}/update", ['times' => 'bad'])->assertNotFound();
        $this->postJson("/api/user/medications/{$id}/log", ['time' => '08:00:00', 'status' => 'taken'])->assertNotFound();
        $this->deleteJson("/api/user/medication/{$id}")->assertNotFound();
        // The same 404 as a missing id, so ids of other users' medications cannot be discovered.
        $this->deleteJson('/api/user/medications/remove-day', ['medication_id' => $id, 'day' => 'monday'])->assertNotFound();
        $this->deleteJson('/api/user/medications/remove-day', ['medication_id' => 999999, 'day' => 'monday'])->assertNotFound();

        $this->assertSame('Vitamin D', Medication::find($id)->name);
        $this->assertSame(0, MedicationLog::count());
    }

    public function test_weekly_schedule_query_count_does_not_grow_with_medications(): void
    {
        $this->createMedication();
        $count = function () {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson('/api/user/medication')->assertOk();

            return count(DB::getQueryLog());
        };

        $few = $count();
        foreach (range(1, 5) as $i) {
            $this->createMedication(['name' => "Med {$i}", 'repeat_type' => 'every_day', 'days' => null]);
        }

        $this->assertSame($few, $count());
    }
}
