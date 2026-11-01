<?php

namespace Tests\Feature\Health;

use App\Models\Admin;
use App\Models\BloodPressureMeasurement;
use App\Models\Child;
use App\Models\Country;
use App\Models\PregnancyWeekContent;
use App\Models\User;
use App\Models\Vaccine;
use App\Models\VaccineScheduleItem;
use App\Modules\HealthService\DefaultHealthContent;
use App\Support\StoreClock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class HealthContentTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-01 09:00:00');
        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function addChild(array $overrides = [])
    {
        return $this->postJson('/api/user/health/child', $overrides + ['child_name' => 'Ali', 'gender' => 'ذكر', 'birth_date' => '2026-01-15']);
    }

    private function editor(): Admin
    {
        $admin = Admin::create(['name' => 'Editor', 'email' => 'editor@example.com', 'password' => 'secret-password']);
        $admin->forceFill(['role' => 'content_editor'])->save();

        return $admin;
    }

    public function test_the_seeded_template_reproduces_the_schedule_the_app_always_had(): void
    {
        $vaccines = $this->addChild()->assertOk()->json('child.vaccines');

        $this->assertCount(count(DefaultHealthContent::DEFAULT_VACCINES), $vaccines);
        $this->assertSame('لقاح عند الولادة', $vaccines[0]['vaccine_name']);
        $this->assertSame('2026-01-15', substr($vaccines[0]['scheduled_date'], 0, 10));
        $this->assertSame('2026-03-15', substr($vaccines[2]['scheduled_date'], 0, 10)); // 2 months
        $this->assertSame('2030-01-15', substr(end($vaccines)['scheduled_date'], 0, 10)); // 4 years
        $this->assertSame('Birth vaccine', $vaccines[0]['vaccine_name_en']);
        $this->assertSame(1, $vaccines[0]['schedule_version']);
    }

    public function test_template_changes_apply_to_new_children_only_and_are_versioned_and_audited(): void
    {
        $firstId = $this->addChild()->json('child.id');
        $item = VaccineScheduleItem::where('code', 'month_2')->firstOrFail();

        $this->actingAs($this->editor(), 'admin')->put(route('dashboard.health_content.vaccines.update', $item), [
            'code' => 'month_2', 'country_id' => $item->country_id, 'name_ar' => 'لقاح الشهرين', 'name_en' => 'Two months',
            'offset_value' => 8, 'offset_unit' => 'week', 'reminder_days_before' => 5, 'sort_order' => $item->sort_order, 'is_active' => 1,
        ])->assertSessionHasNoErrors();
        $this->assertSame(2, $item->fresh()->version);
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'health_content.vaccine.update', 'subject_id' => $item->id]);

        // The first child keeps what it was given.
        $old = Vaccine::where('child_id', $firstId)->where('vaccine_schedule_item_id', $item->id)->firstOrFail();
        $this->assertSame('لقاح عمر شهرين', $old->vaccine_name);
        $this->assertSame(1, $old->schedule_version);

        Sanctum::actingAs($this->user);
        $secondId = $this->addChild(['child_name' => 'Sami'])->json('child.id');
        $new = Vaccine::where('child_id', $secondId)->where('vaccine_schedule_item_id', $item->id)->firstOrFail();
        $this->assertSame('لقاح الشهرين', $new->vaccine_name);
        $this->assertSame('2026-03-12', $new->scheduled_date->toDateString()); // 8 weeks
        $this->assertSame(2, $new->schedule_version);
        $this->assertSame(5, $new->reminder_days_before);
    }

    public function test_removed_and_inactive_entries_are_skipped_for_new_children(): void
    {
        $firstId = $this->addChild()->json('child.id');
        $admin = $this->editor();

        $this->actingAs($admin, 'admin')->delete(route('dashboard.health_content.vaccines.destroy', VaccineScheduleItem::where('code', 'year_4')->first()));
        VaccineScheduleItem::where('code', 'week_1')->update(['is_active' => false]);

        Sanctum::actingAs($this->user);
        $codes = Vaccine::where('child_id', $this->addChild(['child_name' => 'B'])->json('child.id'))->with('scheduleItem')->get()->pluck('scheduleItem.code');
        $this->assertNotContains('year_4', $codes);
        $this->assertNotContains('week_1', $codes);
        $this->assertCount(9, Vaccine::where('child_id', $firstId)->get());
    }

    /** One schedule (the store operates in Egypt only); an empty template falls back to the built-in one. */
    public function test_one_schedule_and_an_empty_template_falls_back(): void
    {
        $this->assertCount(9, $this->addChild()->json('child.vaccines'));

        VaccineScheduleItem::query()->delete();
        $this->assertCount(9, $this->addChild(['child_name' => 'C'])->json('child.vaccines'), 'built-in fallback');
    }

    public function test_child_input_is_validated_and_gender_codes_are_accepted(): void
    {
        $this->addChild(['gender' => 'female'])->assertOk()->assertJsonPath('child.gender', 'أنثى')->assertJsonPath('child.gender_code', 'female');
        $this->addChild(['birth_date' => '2026-12-01'])->assertStatus(422);
        $this->addChild(['birth_date' => '1990-01-01'])->assertStatus(422);
        $this->assertSame(1, Child::count());
    }

    public function test_vaccine_content_needs_the_health_content_permission(): void
    {
        $agent = Admin::create(['name' => 'Agent', 'email' => 'agent@example.com', 'password' => 'secret-password']);
        $agent->forceFill(['role' => 'orders_agent'])->save();

        $this->actingAs($agent, 'admin')->post(route('dashboard.health_content.vaccines.store'), [])->assertForbidden();
        $this->actingAs($this->editor(), 'admin')->post(route('dashboard.health_content.vaccines.store'), [
            'code' => 'hpv', 'name_ar' => 'HPV', 'name_en' => 'HPV', 'offset_value' => 300, 'offset_unit' => 'month', 'reminder_days_before' => 3,
        ])->assertSessionHasErrors('offset_value');
    }

    public function test_pregnancy_content_comes_from_the_dashboard_and_the_calculation_stays_in_code(): void
    {
        $lmp = StoreClock::now()->subDays(7 * 19)->toDateString(); // week 20
        $this->postJson('/api/user/health/calculatePregnancy', ['last_period_date' => $lmp])
            // POST returns the stored calculation (its existing contract); the stage is under result.
            ->assertOk()->assertJsonPath('result.current_week', 20)->assertJsonPath('result.baby_size', 'بحجم موزة');

        PregnancyWeekContent::where('week_from', 11)->update(['size_ar' => 'بحجم مانجو', 'size_en' => 'A mango', 'tip_en' => 'Drink water']);

        $this->withHeader('Accept-Language', 'en')->getJson('/api/user/health/CurrentPregnancyStage')
            ->assertOk()
            ->assertJsonPath('baby_size', 'بحجم مانجو')        // legacy field: Arabic
            ->assertJsonPath('baby_size_label', 'A mango')     // localized
            ->assertJsonPath('tip', 'Drink water')
            ->assertJsonPath('trimester_label', 'Second trimester')
            ->assertJsonPath('due_date', StoreClock::now()->subDays(7 * 19)->addDays(280)->toDateString());

        // Absurd dates are refused, as are future ones.
        $this->postJson('/api/user/health/calculatePregnancy', ['last_period_date' => '2020-01-01'])->assertStatus(422);
        $this->postJson('/api/user/health/calculatePregnancy', ['last_period_date' => '2026-11-02'])->assertStatus(422);
    }

    public function test_blood_pressure_crisis_is_flagged_for_urgent_care(): void
    {
        $post = fn ($s, $d) => $this->postJson('/api/user/health/blood-pressure', ['systolic' => $s, 'diastolic' => $d, 'condition_type' => 'random', 'measured_at' => '2026-11-01 08:00']);

        $post(185, 100)->assertOk()
            ->assertJsonPath('data.evaluation_code', 'hypertensive_crisis')
            ->assertJsonPath('data.evaluation', 'ضغط مرتفع (المرحلة الثانية)')
            ->assertJsonPath('data.requires_urgent_care', true)
            ->assertJsonPath('data.advice', fn ($advice) => is_string($advice) && $advice !== '');
        $post(150, 95)->assertJsonPath('data.requires_urgent_care', false)->assertJsonPath('data.advice', null);
        $post(120, 80)->assertJsonPath('data.guideline', '2017 ACC/AHA');
    }

    public function test_bmi_unit_and_measurement_dates_are_validated(): void
    {
        $this->postJson('/api/user/health/body-weight', ['height' => 170, 'weight' => 70, 'unit' => 'stones'])->assertStatus(422);
        $this->postJson('/api/user/health/body-weight', ['height' => 67, 'weight' => 154, 'unit' => 'imperial'])->assertOk()->assertJsonPath('data.status_code', 'normal');
        $this->postJson('/api/user/health/blood-sugar', ['value' => 90, 'condition_type' => 'fasting', 'measured_at' => '2026-11-05 08:00'])->assertStatus(422);
        $this->postJson('/api/user/health/blood-sugar', ['value' => 90, 'condition_type' => 'قبل الأكل', 'measured_at' => '2026-11-01 08:00'])
            ->assertOk()->assertJsonPath('data.condition_code', 'before_meal');
    }

    public function test_labels_follow_accept_language_while_legacy_fields_stay_arabic(): void
    {
        $this->postJson('/api/user/health/ovulation', ['start_day_of_cycle' => '2026-10-20', 'cycle_length' => 28, 'period_length' => 5])->assertOk()
            ->assertJsonPath('message', 'تم حساب التبويض بنجاح')
            ->assertJsonPath('data.days.0.phase', 'حيض')
            ->assertJsonPath('data.days.0.phase_label', 'حيض');

        $this->withHeader('Accept-Language', 'en')->getJson('/api/user/health/ovulation/current')->assertOk()
            ->assertJsonPath('message', 'Cycle calculated')
            // Ovulation on Nov 3 (start + 28 - 14): Nov 1 is two days before.
            ->assertJsonPath('today.phase_code', 'fertile_high')
            ->assertJsonPath('today.phase', 'خصوبة عالة')
            ->assertJsonPath('today.phase_label', 'High fertility');
    }

    public function test_history_is_capped_without_paging_and_paginated_on_request(): void
    {
        foreach (range(1, 3) as $i) {
            BloodPressureMeasurement::create(['user_id' => $this->user->id, 'systolic' => 120 + $i, 'diastolic' => 80, 'condition_type' => 'x', 'measured_at' => now()]);
        }

        $this->getJson('/api/user/health/blood-pressure')->assertOk()->assertJsonCount(3, 'data')->assertJsonPath('meta.truncated', false);
        $this->getJson('/api/user/health/blood-pressure?per_page=2&page=2')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 3)->assertJsonPath('meta.last_page', 2);
        $this->getJson('/api/user/health/blood-pressure?per_page=500')->assertStatus(422);
    }
}
