<?php

namespace App\Console\Commands;

use App\Modules\HealthService\Services\HealthReminderService;
use Illuminate\Console\Command;

class SendHealthReminders extends Command
{
    protected $signature = 'health:send-reminders {--vaccines} {--medications}';

    protected $description = 'Queue due vaccination and medication reminders (never twice for the same event)';

    public function handle(HealthReminderService $reminders): int
    {
        $all = !$this->option('vaccines') && !$this->option('medications');

        if ($all || $this->option('vaccines')) {
            $this->info('Vaccine reminders queued: '.$reminders->queueVaccineReminders());
        }

        if ($all || $this->option('medications')) {
            $this->info('Medication reminders queued: '.$reminders->queueMedicationReminders());
        }

        if ($all || $this->option('medications')) {
            // Every few minutes: reminders whose job was lost or whose worker died are queued again.
            $this->info('Reminders requeued: '.$reminders->recoverStale());
        }

        return self::SUCCESS;
    }
}
