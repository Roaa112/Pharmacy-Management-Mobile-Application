<?php

namespace App\Modules\HealthService\Services;

use App\Models\Setting;

/**
 * Health › Settings, stored on the settings row (`health_settings` JSON, plus the two reminder
 * switches that already had their own columns). Every value has a default equal to the behaviour
 * before these settings existed, so an untouched installation behaves exactly as before.
 */
final class HealthSettings
{
    /** Customer health tools that can be switched off. */
    public const TOOLS = ['bmi', 'blood_sugar', 'blood_pressure', 'ovulation', 'pregnancy', 'vaccines', 'medication'];

    public const PHASES = ['menstruation', 'not_fertile', 'fertile_low', 'fertile_medium', 'fertile_high', 'ovulation', 'fertile_after', 'post_ovulation'];

    public const GUIDELINE_KEYS = ['bmi', 'blood_sugar', 'blood_pressure', 'ovulation', 'pregnancy'];

    public const DEFAULTS = [
        'tools' => ['bmi' => true, 'blood_sugar' => true, 'blood_pressure' => true, 'ovulation' => true, 'pregnancy' => true, 'vaccines' => true, 'medication' => true],
        'disclaimer_ar' => null,
        'disclaimer_en' => null,
        'guidelines' => [
            'bmi' => 'WHO adult BMI classification',
            'blood_sugar' => 'ADA Standards of Care (mg/dL)',
            'blood_pressure' => '2017 ACC/AHA',
            'ovulation' => 'Fixed 14-day luteal phase; fertile window per Wilcox et al. 1995',
            'pregnancy' => 'Naegele rule (LMP + 280 days); ACOG trimesters',
        ],
        'bmi_ideal_min' => 18.5,
        'bmi_ideal_max' => 24.9,
        'cycle_length_min' => 21,
        'cycle_length_max' => 35,
        'period_length_min' => 3,
        'period_length_max' => 10,
        'luteal_phase_days' => 14,
        // Pregnancy chance per cycle phase, in percent (shape of Wilcox et al., NEJM 1995).
        'phase_chances' => [
            'menstruation' => 0, 'not_fertile' => 0, 'fertile_low' => 10, 'fertile_medium' => 15,
            'fertile_high' => 25, 'ovulation' => 30, 'fertile_after' => 5, 'post_ovulation' => 0,
        ],
        'vaccine_reminder_time' => '09:00',
        'vaccine_default_lead_days' => 3,
        'medication_window_minutes' => 15,
    ];

    private array $values;

    public function __construct(private Setting $setting)
    {
        $stored = is_array($setting->health_settings) ? $setting->health_settings : [];
        $this->values = array_replace_recursive(self::DEFAULTS, array_intersect_key($stored, self::DEFAULTS));
    }

    public static function current(): self
    {
        return new self(Setting::current());
    }

    public function setting(): Setting
    {
        return $this->setting;
    }

    public function all(): array
    {
        return $this->values;
    }

    public function toolEnabled(string $tool): bool
    {
        return (bool) ($this->values['tools'][$tool] ?? true);
    }

    public function disclaimer(string $locale): ?string
    {
        $text = $locale === 'en' ? ($this->values['disclaimer_en'] ?: $this->values['disclaimer_ar']) : $this->values['disclaimer_ar'];

        return filled($text) ? $text : null;
    }

    public function guideline(string $key): ?string
    {
        $text = $this->values['guidelines'][$key] ?? null;

        return filled($text) ? $text : null;
    }

    public function bmiIdealRange(): array
    {
        return [(float) $this->values['bmi_ideal_min'], (float) $this->values['bmi_ideal_max']];
    }

    public function cycleLengthRange(): array
    {
        return [(int) $this->values['cycle_length_min'], (int) $this->values['cycle_length_max']];
    }

    public function periodLengthRange(): array
    {
        return [(int) $this->values['period_length_min'], (int) $this->values['period_length_max']];
    }

    public function lutealPhaseDays(): int
    {
        return (int) $this->values['luteal_phase_days'];
    }

    /** "30%" — the shape the app has always received. */
    public function phaseChance(string $phase): string
    {
        return ((int) ($this->values['phase_chances'][$phase] ?? 0)).'%';
    }

    public function vaccineReminderTime(): string
    {
        return (string) $this->values['vaccine_reminder_time'];
    }

    public function vaccineDefaultLeadDays(): int
    {
        return (int) $this->values['vaccine_default_lead_days'];
    }

    public function medicationWindowMinutes(): int
    {
        return (int) $this->values['medication_window_minutes'];
    }

    public function vaccineRemindersEnabled(): bool
    {
        return (bool) $this->setting->vaccine_reminders_enabled;
    }

    public function medicationRemindersEnabled(): bool
    {
        return (bool) $this->setting->medication_reminders_enabled;
    }

    /** What the app needs to build its health screens (GET /api/v1/user/health/config). */
    public function toPublicArray(string $locale): array
    {
        return [
            'tools' => array_map('boolval', $this->values['tools']),
            'disclaimer' => $this->disclaimer($locale),
            'guidelines' => $this->values['guidelines'],
            'ovulation' => [
                'cycle_length' => ['min' => $this->cycleLengthRange()[0], 'max' => $this->cycleLengthRange()[1]],
                'period_length' => ['min' => $this->periodLengthRange()[0], 'max' => $this->periodLengthRange()[1]],
            ],
            'bmi_ideal_range' => ['min' => $this->bmiIdealRange()[0], 'max' => $this->bmiIdealRange()[1]],
            'reminders' => [
                'vaccines' => $this->vaccineRemindersEnabled(),
                'medications' => $this->medicationRemindersEnabled(),
            ],
        ];
    }
}
