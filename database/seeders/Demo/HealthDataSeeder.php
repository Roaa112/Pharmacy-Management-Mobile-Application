<?php

namespace Database\Seeders\Demo;

use App\Jobs\SendHealthReminder;
use App\Models\Child;
use App\Models\DeviceToken;
use App\Models\NotificationDelivery;
use App\Models\Vaccine;
use App\Modules\HealthService\Services\HealthReminderService;
use App\Models\User;
use App\Modules\HealthService\Services\HealthService;
use App\Support\StoreClock;
use Illuminate\Database\Seeder;

/**
 * Health tracker data created through HealthService, so stored results are exactly what the
 * app computes: blood pressure, blood sugar, BMI, ovulation cycles, a pregnancy and children
 * with their vaccine schedule.
 */
class HealthDataSeeder extends Seeder
{
    public function run(): void
    {
        $health = app(HealthService::class);
        $today = StoreClock::now()->startOfDay();
        $user = fn (string $email) => User::where('email', $email)->firstOrFail();

        $youssef = $user('youssef@example.com');
        $omar = $user('omar@example.com');
        $sara = $user('sara@example.com');
        $mona = $user('mona@example.com');

        // Blood pressure: a week of readings from normal to stage 1, one after a meal.
        $pressure = [
            [$youssef, 118, 76, 'random', 6], [$youssef, 126, 78, 'random', 5], [$youssef, 134, 86, 'after_meal', 3],
            [$youssef, 142, 92, 'random', 1], [$omar, 112, 72, 'random', 4], [$omar, 88, 58, 'random', 0],
        ];
        foreach ($pressure as [$who, $systolic, $diastolic, $condition, $daysAgo]) {
            $health->storeBloodPressure($who->id, [
                'systolic' => $systolic, 'diastolic' => $diastolic, 'condition_type' => $condition,
                'measured_at' => $today->copy()->subDays($daysAgo)->setTime(8, 30)->toDateTimeString(),
            ]);
        }

        // Blood sugar (mg/dL) for the diabetic customer, in every condition.
        $sugar = [
            [92, 'fasting', 6], [128, 'fasting', 4], [165, 'after_meal', 4], [134, 'after_meal', 2],
            [64, 'random', 1], [210, 'random', 0], [98, 'before_breakfast', 0],
        ];
        foreach ($sugar as [$value, $condition, $daysAgo]) {
            $health->storeBloodSugar($youssef->id, [
                'value' => $value, 'condition_type' => $condition,
                'measured_at' => $today->copy()->subDays($daysAgo)->setTime(9, 0)->toDateTimeString(),
            ]);
        }

        // BMI in metric and imperial units.
        $health->calculateBodyWeight($youssef->id, ['height' => 175, 'weight' => 92, 'unit' => 'metric']);
        $health->calculateBodyWeight($omar->id, ['height' => 180, 'weight' => 74, 'unit' => 'metric']);
        $health->calculateBodyWeight($sara->id, ['height' => 64, 'weight' => 128, 'unit' => 'imperial']);

        // Two cycles for Sara; the calendar uses the most recent one.
        $health->calculateOvulation($sara->id, ['start_day_of_cycle' => $today->copy()->subDays(37)->toDateString(), 'cycle_length' => 28, 'period_length' => 5]);
        $health->calculateOvulation($sara->id, ['start_day_of_cycle' => $today->copy()->subDays(9)->toDateString(), 'cycle_length' => 28, 'period_length' => 5]);

        // Mona is in her second trimester (about 16 weeks).
        $health->calculatePregnancy($mona->id, ['last_period_date' => $today->copy()->subDays(110)->toDateString()]);

        $this->children($health, $mona, $today);
    }

    private function children(HealthService $health, User $parent, $today): void
    {
        $children = [
            ['Adam', 'ذكر', $today->copy()->subMonths(7)],
            ['Laila', 'أنثى', $today->copy()->subYears(3)->subMonths(2)],
        ];

        foreach ($children as [$name, $gender, $birthDate]) {
            $child = Child::create([
                'user_id' => $parent->id,
                'child_name' => $name,
                'gender' => $gender,
                'birth_date' => $birthDate->toDateString(),
            ]);

            $health->generateDefaultVaccines($child);

            // Doses already due are done, except the most recent one (shown as overdue).
            $due = $child->vaccines()->where('scheduled_date', '<=', $today)->orderBy('scheduled_date')->get();
            $due->slice(0, -1)->each->update(['is_completed' => true]);
        }

        $this->seedReminders($parent);
    }

    /**
     * A registered phone and one reminder already sent (log push driver), so the app's
     * notification history and the dashboard's reminder log have something to show.
     */
    private function seedReminders(User $parent): void
    {
        DeviceToken::firstOrCreate(['token_hash' => DeviceToken::hashOf('demo-device-token-'.$parent->id)], [
            'user_id' => $parent->id, 'token' => 'demo-device-token-'.$parent->id, 'platform' => 'android', 'locale' => 'ar', 'last_used_at' => now(),
        ]);

        $vaccine = Vaccine::whereIn('child_id', $parent->children()->pluck('id'))->where('is_completed', false)->orderBy('scheduled_date')->first();
        if (!$vaccine) {
            return;
        }

        $delivery = NotificationDelivery::firstOrCreate(
            ['type' => HealthReminderService::VACCINE, 'reference_type' => 'vaccine', 'reference_id' => $vaccine->id, 'occurrence' => $vaccine->scheduled_date->toDateString()],
            ['user_id' => $parent->id, 'status' => NotificationDelivery::STATUS_SENDING],
        );

        SendHealthReminder::dispatchSync($delivery->id);
    }
}
