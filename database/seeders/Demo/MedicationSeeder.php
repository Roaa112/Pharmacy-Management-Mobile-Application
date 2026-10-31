<?php

namespace Database\Seeders\Demo;

use App\Models\MedicationLog;
use App\Models\User;
use App\Modules\Medication\Services\MedicationService;
use App\Support\StoreClock;
use Illuminate\Database\Seeder;

/**
 * Medication reminders (every day, specific days and once) created through MedicationService,
 * with taken/missed dose logs for the last few days.
 */
class MedicationSeeder extends Seeder
{
    public function run(): void
    {
        $service = app(MedicationService::class);
        $today = StoreClock::now()->startOfDay();

        $medications = [
            ['youssef@example.com', 'Metformin 500 mg', 'بعد الأكل', 1, 'every_day', ['08:00:00', '20:00:00'], null],
            ['youssef@example.com', 'Vitamin D3 5000 IU', null, 1, 'specific_days', ['09:00:00'], ['saturday', 'tuesday', 'thursday']],
            ['omar@example.com', 'Omega-3 Fish Oil', 'With breakfast', 2, 'every_day', ['09:30:00'], null],
            ['sara@example.com', 'Prenatal Multivitamin', null, 1, 'every_day', ['10:00:00'], null],
            ['mona@example.com', 'Iron supplement', 'لا يؤخذ مع الشاي', 1, 'specific_days', ['13:00:00'], ['sunday', 'wednesday']],
            ['mona@example.com', 'Antibiotic course reminder', 'Last dose', 1, 'once', ['21:00:00'], [strtolower($today->copy()->addDay()->format('l'))]],
        ];

        foreach ($medications as [$email, $name, $notes, $quantity, $repeat, $times, $days]) {
            $user = User::where('email', $email)->firstOrFail();

            $medication = $service->create([
                'name' => $name,
                'notes' => $notes,
                'quantity_per_time' => $quantity,
                'repeat_type' => $repeat,
                'times' => $times,
                'days' => $days,
            ], $user);

            if ($repeat !== 'every_day') {
                continue;
            }

            // The last three days: mostly taken, the evening dose of two days ago missed.
            foreach ([3, 2, 1] as $daysAgo) {
                foreach ($times as $i => $time) {
                    MedicationLog::create([
                        'user_id' => $user->id,
                        'medication_id' => $medication->id,
                        'shown_date' => $today->copy()->subDays($daysAgo)->toDateString(),
                        'time' => $time,
                        'status' => ($daysAgo === 2 && $i === 1) ? 'missed' : 'taken',
                    ]);
                }
            }
        }
    }
}
