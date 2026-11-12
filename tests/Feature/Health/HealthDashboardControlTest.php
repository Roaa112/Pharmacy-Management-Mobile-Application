<?php

namespace Tests\Feature\Health;

use App\Models\Admin;
use App\Models\AdminAuditLog;
use App\Models\HealthResultRule;
use App\Models\User;
use App\Modules\HealthService\Services\HealthReminderService;
use App\Modules\HealthService\Services\HealthRuleDefaults;
use App\Modules\HealthService\Services\HealthSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** The health tools are controlled from the dashboard: result rules, texts and settings. */
class HealthDashboardControlTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-01 09:00:00');
        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function admin(string $role): Admin
    {
        $admin = Admin::create(['name' => ucfirst($role), 'email' => "{$role}@example.com", 'password' => 'secret-password']);
        $admin->forceFill(['role' => $role])->save();

        return $admin;
    }

    private function bmi(float $weight = 73.7, float $height = 170)
    {
        Sanctum::actingAs($this->user);

        return $this->postJson('/api/user/health/body-weight', ['height' => $height, 'weight' => $weight, 'unit' => 'metric']);
    }

    private function rule(string $metric, string $code, ?string $condition = null): HealthResultRule
    {
        return HealthResultRule::where(['metric' => $metric, 'code' => $code, 'condition' => $condition])->firstOrFail();
    }

    private function ruleForm(HealthResultRule $rule, array $changes = []): array
    {
        return array_merge($rule->only(['code', 'priority', 'sort_order', 'condition', 'primary_from', 'primary_to', 'secondary_from', 'secondary_to',
            'label_ar', 'label_en', 'legacy_ar', 'message_ar', 'message_en']), ['requires_urgent_care' => (int) $rule->requires_urgent_care, 'is_active' => 1], $changes);
    }

    private function settingsForm(array $changes = []): array
    {
        $h = HealthSettings::DEFAULTS;

        return array_replace_recursive([
            'tools' => array_map('intval', $h['tools']),
            'guidelines' => $h['guidelines'],
            'bmi_ideal_min' => $h['bmi_ideal_min'], 'bmi_ideal_max' => $h['bmi_ideal_max'],
            'cycle_length_min' => $h['cycle_length_min'], 'cycle_length_max' => $h['cycle_length_max'],
            'period_length_min' => $h['period_length_min'], 'period_length_max' => $h['period_length_max'],
            'luteal_phase_days' => $h['luteal_phase_days'], 'phase_chances' => $h['phase_chances'],
            'vaccine_reminders_enabled' => 1, 'vaccine_reminder_time' => $h['vaccine_reminder_time'],
            'vaccine_default_lead_days' => $h['vaccine_default_lead_days'],
            'medication_reminders_enabled' => 0, 'medication_window_minutes' => $h['medication_window_minutes'],
        ], $changes);
    }

    public function test_the_seeded_rules_are_the_guideline_defaults(): void
    {
        $this->assertSame(count(HealthRuleDefaults::rows()), HealthResultRule::count());

        $this->bmi()->assertOk()
            ->assertJsonPath('data.bmi', 25.5)
            ->assertJsonPath('data.status_code', 'overweight')
            ->assertJsonPath('data.status', 'زيادة في الوزن')
            ->assertJsonPath('data.description', __('health.bmi.overweight.description', [], 'ar'))
            ->assertJsonPath('data.requires_urgent_care', false)
            ->assertJsonPath('data.disclaimer', null);
    }

    public function test_an_admin_chooses_the_message_and_threshold_of_a_bmi_category(): void
    {
        $specialist = $this->admin('health_specialist');
        $overweight = $this->rule(HealthResultRule::BMI, 'overweight');

        $this->actingAs($specialist, 'admin')
            ->put(route('dashboard.health_content.rules.update', $overweight), $this->ruleForm($overweight, [
                'message_ar' => 'وزنك أعلى من الطبيعي، احجز استشارة تغذية مجانية من الصيدلية.',
                'message_en' => 'Your weight is above normal. Book a free nutrition consultation at the pharmacy.',
                'requires_urgent_care' => 1,
            ]))->assertRedirect()->assertSessionHasNoErrors();

        $this->bmi()->assertOk()
            ->assertJsonPath('data.status_code', 'overweight')
            ->assertJsonPath('data.description', 'وزنك أعلى من الطبيعي، احجز استشارة تغذية مجانية من الصيدلية.')
            ->assertJsonPath('data.requires_urgent_care', true);

        $this->withHeader('Accept-Language', 'en')->bmi()
            ->assertJsonPath('data.description_label', 'Your weight is above normal. Book a free nutrition consultation at the pharmacy.');

        // Moving the threshold changes the category.
        $normal = $this->rule(HealthResultRule::BMI, 'normal');
        $this->actingAs($specialist, 'admin')->put(route('dashboard.health_content.rules.update', $normal), $this->ruleForm($normal, ['primary_to' => 26]))->assertSessionHasNoErrors();
        $this->actingAs($specialist, 'admin')->put(route('dashboard.health_content.rules.update', $overweight->fresh()), $this->ruleForm($overweight->fresh(), ['primary_from' => 26]))->assertSessionHasNoErrors();

        $this->bmi()->assertJsonPath('data.status_code', 'normal');
        $this->assertSame(3, AdminAuditLog::where('action', 'health_rules.update')->count());
    }

    public function test_blood_pressure_messages_and_urgency_follow_their_rule(): void
    {
        $stage2 = $this->rule(HealthResultRule::BLOOD_PRESSURE, 'stage_2');
        $this->actingAs($this->admin('health_specialist'), 'admin')
            ->put(route('dashboard.health_content.rules.update', $stage2), $this->ruleForm($stage2, ['message_en' => 'Call your doctor this week.', 'message_ar' => 'اتصل بطبيبك هذا الأسبوع.']))
            ->assertSessionHasNoErrors();

        Sanctum::actingAs($this->user);
        $reading = fn (int $s, int $d) => $this->withHeader('Accept-Language', 'en')->postJson('/api/user/health/blood-pressure', [
            'systolic' => $s, 'diastolic' => $d, 'condition_type' => 'random', 'measured_at' => '2026-10-31 10:00',
        ]);

        $reading(150, 85)->assertOk()->assertJsonPath('data.evaluation_code', 'stage_2')
            ->assertJsonPath('data.advice', 'Call your doctor this week.')->assertJsonPath('data.requires_urgent_care', false);
        $reading(190, 100)->assertJsonPath('data.evaluation_code', 'hypertensive_crisis')->assertJsonPath('data.requires_urgent_care', true);
        $reading(118, 75)->assertJsonPath('data.evaluation_code', 'normal')->assertJsonPath('data.advice', null);
    }

    public function test_blood_sugar_rules_per_condition_and_unknown_readings(): void
    {
        Sanctum::actingAs($this->user);
        $reading = fn (float $value, string $condition) => $this->postJson('/api/user/health/blood-sugar', [
            'value' => $value, 'condition_type' => $condition, 'measured_at' => '2026-10-31 10:00',
        ]);

        $fastingHigh = $this->rule(HealthResultRule::BLOOD_SUGAR, 'high', 'fasting');
        $fastingHigh->update(['primary_from' => 126, 'message_ar' => 'قراءة صائم مرتفعة.']);
        $this->rule(HealthResultRule::BLOOD_SUGAR, 'normal', 'fasting')->update(['primary_to' => 126]);

        $reading(110, 'fasting')->assertOk()->assertJsonPath('data.evaluation_code', 'normal');
        $reading(130, 'صائم')->assertJsonPath('data.evaluation_code', 'high')->assertJsonPath('data.advice', 'قراءة صائم مرتفعة.');
        $reading(65, 'after_meal')->assertJsonPath('data.evaluation_code', 'low');
        // A condition the rules do not know only matches rules for any condition.
        $reading(150, 'other')->assertJsonPath('data.evaluation_code', 'unknown')->assertJsonPath('data.evaluation', 'غير معروف');
    }

    public function test_viewing_rules_needs_health_content_and_changing_them_needs_health_rules(): void
    {
        $editor = $this->admin('content_editor');
        $rule = $this->rule(HealthResultRule::BMI, 'overweight');

        $this->actingAs($editor, 'admin')->get(route('dashboard.health_content.rules.index'))->assertOk()->assertSee('Manage health result rules');
        $this->actingAs($editor, 'admin')->put(route('dashboard.health_content.rules.update', $rule), $this->ruleForm($rule, ['message_en' => 'x']))->assertForbidden();
        $this->actingAs($editor, 'admin')->post(route('dashboard.health_content.rules.reset', 'bmi'))->assertForbidden();
        $this->assertNotSame('x', $rule->fresh()->message_en);

        // Health texts and settings are health content.
        $this->actingAs($editor, 'admin')->put(route('dashboard.health_content.settings.update'), $this->settingsForm())->assertRedirect()->assertSessionHasNoErrors();
    }

    public function test_rules_page_previews_a_reading_and_warns_about_gaps(): void
    {
        $specialist = $this->admin('health_specialist');
        $this->rule(HealthResultRule::BMI, 'overweight')->update(['is_active' => false]);

        $this->actingAs($specialist, 'admin')
            ->get(route('dashboard.health_content.rules.index', ['try_metric' => 'blood_pressure', 'try_value' => 135, 'try_value2' => 70]))
            ->assertOk()
            ->assertSee('stage_1')
            ->assertSee('Bmi: readings from 25 to under 30 match no rule', false);
    }

    public function test_reset_restores_the_guideline_defaults_and_no_rules_means_defaults(): void
    {
        HealthResultRule::where('metric', HealthResultRule::BMI)->delete();
        $this->bmi()->assertJsonPath('data.status_code', 'overweight'); // falls back to the defaults

        $this->actingAs($this->admin('health_specialist'), 'admin')->post(route('dashboard.health_content.rules.reset', 'bmi'))->assertRedirect();

        $this->assertSame(6, HealthResultRule::where('metric', HealthResultRule::BMI)->count());
        $this->assertSame(1, AdminAuditLog::where('action', 'health_rules.reset')->count());
    }

    public function test_rule_form_rejects_an_inverted_range(): void
    {
        $rule = $this->rule(HealthResultRule::BMI, 'normal');

        $this->actingAs($this->admin('health_specialist'), 'admin')
            ->put(route('dashboard.health_content.rules.update', $rule), $this->ruleForm($rule, ['primary_from' => 30, 'primary_to' => 20]))
            ->assertSessionHasErrors('primary_to');
    }

    public function test_medical_messages_are_managed_only_on_the_result_rules_page(): void
    {
        $admin = $this->admin("health_specialist");

        // The separate Health texts page no longer exists.
        $this->actingAs($admin, "admin")->get("/dashboard/health-content/texts")->assertNotFound();

        $this->actingAs($admin, "admin")->get(route("dashboard.health_content.rules.index"))->assertOk()
            ->assertDontSee("Health texts")
            ->assertSee("Result rules &amp; messages", false)
            ->assertSee("id=\"rules-blood_pressure\"", false)
            ->assertSee("id=\"rules-blood_sugar\"", false)
            ->assertSee("id=\"rules-bmi\"", false);
    }

    public function test_a_switched_off_tool_refuses_new_entries_and_the_app_can_read_the_switches(): void
    {
        $this->actingAs($this->admin('content_editor'), 'admin')
            ->put(route('dashboard.health_content.settings.update'), $this->settingsForm([
                'tools' => ['bmi' => 0, 'medication' => 0],
                'disclaimer_ar' => 'هذه النتائج للتوعية فقط.',
                'disclaimer_en' => 'For information only.',
            ]))->assertSessionHasNoErrors();

        $this->bmi()->assertForbidden()->assertJsonPath('code', 'health_tool_disabled')->assertJsonPath('tool', 'bmi');
        $this->getJson('/api/user/health/weight')->assertOk(); // history stays readable
        $this->postJson('/api/user/medication', ['name' => 'X', 'quantity_per_time' => 1, 'repeat_type' => 'every_day', 'times' => ['08:00']])
            ->assertForbidden()->assertJsonPath('code', 'health_tool_disabled');

        $this->withHeader('Accept-Language', 'en')->getJson('/api/v1/user/health/config')->assertOk()
            ->assertJsonPath('data.tools.bmi', false)
            ->assertJsonPath('data.tools.blood_pressure', true)
            ->assertJsonPath('data.disclaimer', 'For information only.');

        $this->flushHeaders()->postJson('/api/user/health/blood-pressure', ['systolic' => 120, 'diastolic' => 70, 'condition_type' => 'random', 'measured_at' => '2026-10-31 10:00'])
            ->assertOk()->assertJsonPath('data.disclaimer', 'هذه النتائج للتوعية فقط.');
    }

    public function test_ovulation_limits_luteal_phase_and_chances_follow_the_settings(): void
    {
        $this->actingAs($this->admin('content_editor'), 'admin')
            ->put(route('dashboard.health_content.settings.update'), $this->settingsForm([
                'cycle_length_max' => 40, 'luteal_phase_days' => 12, 'phase_chances' => ['ovulation' => 33],
            ]))->assertSessionHasNoErrors();

        Sanctum::actingAs($this->user);
        $data = $this->postJson('/api/user/health/ovulation', ['start_day_of_cycle' => '2026-10-01', 'cycle_length' => 38, 'period_length' => 5])
            ->assertOk()->json('data');

        $this->assertSame('2026-10-27', $data['ovulation_date']); // day 38 - 12
        $this->assertSame('33%', collect($data['days'])->firstWhere('date', '2026-10-27')['pregnancy_chance']);

        $this->postJson('/api/user/health/ovulation', ['start_day_of_cycle' => '2026-10-01', 'cycle_length' => 41, 'period_length' => 5])
            ->assertUnprocessable();
    }

    public function test_settings_validation_and_audit(): void
    {
        $editor = $this->admin('content_editor');

        $this->actingAs($editor, 'admin')->put(route('dashboard.health_content.settings.update'), $this->settingsForm(['cycle_length_min' => 14, 'luteal_phase_days' => 14]))
            ->assertSessionHasErrors('cycle_length_min');
        $this->actingAs($editor, 'admin')->put(route('dashboard.health_content.settings.update'), $this->settingsForm(['bmi_ideal_max' => 15]))
            ->assertSessionHasErrors('bmi_ideal_max');

        $this->actingAs($editor, 'admin')->put(route('dashboard.health_content.settings.update'), $this->settingsForm(['medication_reminders_enabled' => 1]))->assertSessionHasNoErrors();
        $this->assertTrue(HealthSettings::current()->medicationRemindersEnabled());
        $this->assertSame(1, AdminAuditLog::where('action', 'health_settings.update')->count());

        $this->actingAs($editor, 'admin')->get(route('dashboard.health_content.settings.edit'))->assertOk()->assertSee('Health tools in the app');
    }

    public function test_vaccine_reminders_wait_for_the_configured_send_time(): void
    {
        $reminders = app(HealthReminderService::class);

        // 06:30 UTC = 08:30 in Cairo (UTC+2 in November): before the default 09:00.
        $this->assertFalse($reminders->vaccineSendTimeReached(Carbon::parse('2026-11-01 08:30', 'Africa/Cairo')));
        $this->assertTrue($reminders->vaccineSendTimeReached(Carbon::parse('2026-11-01 09:00', 'Africa/Cairo')));

        $this->actingAs($this->admin('content_editor'), 'admin')
            ->put(route('dashboard.health_content.settings.update'), $this->settingsForm(['vaccine_reminder_time' => '07:30']))->assertSessionHasNoErrors();

        $this->assertTrue($reminders->vaccineSendTimeReached(Carbon::parse('2026-11-01 08:30', 'Africa/Cairo')));
    }
}
