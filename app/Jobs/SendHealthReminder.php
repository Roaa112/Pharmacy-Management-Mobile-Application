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
 * Sends one reminder. Safe however often it runs and however many workers run it at once: it
 * first claims the delivery (NotificationDelivery::claim(), one conditional UPDATE), so only one
 * worker sends it; it re-checks that the event still needs reminding (a vaccine may have been
 * given since). A failure gives the claim back and is retried by the queue (and, if the job
 * itself is lost, by the scheduler: HealthReminderService::recoverStale()); after
 * MAX_ATTEMPTS it is recorded as failed. A worker killed between sending and recording "sent"
 * can cause one repeat after CLAIM_TIMEOUT_MINUTES (push delivery is at-least-once).
 */
class SendHealthReminder implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = NotificationDelivery::MAX_ATTEMPTS;

    public array $backoff = [60, 300];

    public function __construct(public int $deliveryId)
    {
    }

    public function handle(): void
    {
        if (!NotificationDelivery::claim($this->deliveryId)) {
            return; // sent, skipped or failed already, or another worker is sending it
        }

        $delivery = NotificationDelivery::with('user.deviceTokens')->findOrFail($this->deliveryId);
        $user = $delivery->user;

        $notification = $user ? match ($delivery->type) {
            HealthReminderService::VACCINE => $this->vaccineReminder($delivery, $this->locale($user)),
            HealthReminderService::MEDICATION => $this->medicationReminder($delivery, $this->locale($user)),
            default => null,
        } : null;

        if (!$notification) {
            $this->finish($delivery, ['status' => NotificationDelivery::STATUS_SKIPPED]);

            return;
        }

        try {
            $user->notify($notification);
        } catch (Throwable $e) {
            $last = $delivery->attempts >= NotificationDelivery::MAX_ATTEMPTS;
            // Give the claim back so a retry can take it; the last attempt records the failure.
            $this->finish($delivery, ['status' => $last ? NotificationDelivery::STATUS_FAILED : NotificationDelivery::STATUS_SENDING,
                'claimed_at' => null, 'error' => mb_substr($e->getMessage(), 0, 250)]);
            report($e);
            if (!$last) {
                throw $e;
            }

            return;
        }

        $this->finish($delivery, ['status' => NotificationDelivery::STATUS_SENT, 'devices' => $user->deviceTokens->count(), 'sent_at' => now(), 'error' => null]);
    }

    /** Record the outcome only while this worker still holds the claim. */
    private function finish(NotificationDelivery $delivery, array $values): void
    {
        NotificationDelivery::whereKey($delivery->id)->where('status', NotificationDelivery::STATUS_DELIVERING)
            ->where('claimed_at', $delivery->getRawOriginal('claimed_at'))->update($values + ['updated_at' => now()]);
    }

    private function locale($user): string
    {
        return $user->deviceTokens->first()?->locale === 'en' ? 'en' : 'ar';
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
