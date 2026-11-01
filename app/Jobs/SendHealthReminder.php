<?php

namespace App\Jobs;

use App\Models\MedicationTime;
use App\Models\NotificationDelivery;
use App\Models\Vaccine;
use App\Modules\HealthService\Services\HealthReminderService;
use App\Notifications\HealthReminder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Sends one claimed reminder. Safe to run twice: it only acts on a delivery still "sending",
 * and re-checks that the event still needs reminding (a vaccine may have been given since).
 */
class SendHealthReminder implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public int $deliveryId)
    {
    }

    public function handle(): void
    {
        $delivery = NotificationDelivery::with('user.deviceTokens')->find($this->deliveryId);

        if (!$delivery || $delivery->status !== NotificationDelivery::STATUS_SENDING || !$delivery->user) {
            return;
        }

        $user = $delivery->user;
        $locale = $user->deviceTokens->first()?->locale === 'en' ? 'en' : 'ar';

        $notification = match ($delivery->type) {
            HealthReminderService::VACCINE => $this->vaccineReminder($delivery, $locale),
            HealthReminderService::MEDICATION => $this->medicationReminder($delivery, $locale),
            default => null,
        };

        if (!$notification) {
            $delivery->update(['status' => NotificationDelivery::STATUS_SKIPPED]);

            return;
        }

        try {
            $user->notify($notification);
            $delivery->update(['status' => NotificationDelivery::STATUS_SENT, 'devices' => $user->deviceTokens->count(), 'sent_at' => now()]);
        } catch (Throwable $e) {
            $delivery->update(['status' => NotificationDelivery::STATUS_FAILED, 'error' => mb_substr($e->getMessage(), 0, 250)]);
            report($e);
        }
    }

    private function vaccineReminder(NotificationDelivery $delivery, string $locale): ?HealthReminder
    {
        $vaccine = Vaccine::with('child')->find($delivery->reference_id);

        if (!$vaccine || $vaccine->is_completed || !$vaccine->child) {
            return null;
        }

        $name = $locale === 'en' ? ($vaccine->vaccine_name_en ?: $vaccine->vaccine_name) : $vaccine->vaccine_name;

        return new HealthReminder(
            'vaccine',
            __('health.reminders.vaccine_title', [], $locale),
            __('health.reminders.vaccine_body', ['vaccine' => $name, 'child' => $vaccine->child->child_name, 'date' => $vaccine->scheduled_date->toDateString()], $locale),
            ['vaccine_id' => $vaccine->id, 'child_id' => $vaccine->child_id, 'scheduled_date' => $vaccine->scheduled_date->toDateString()],
        );
    }

    private function medicationReminder(NotificationDelivery $delivery, string $locale): ?HealthReminder
    {
        $time = MedicationTime::with('medication')->find($delivery->reference_id);

        if (!$time?->medication) {
            return null;
        }

        return new HealthReminder(
            'medication',
            __('health.reminders.medication_title', [], $locale),
            __('health.reminders.medication_body', ['medication' => $time->medication->name, 'quantity' => $time->medication->quantity_per_time], $locale),
            ['medication_id' => $time->medication_id, 'time' => (string) $time->getRawOriginal('time')],
        );
    }
}
