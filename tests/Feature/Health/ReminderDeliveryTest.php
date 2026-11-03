<?php

namespace Tests\Feature\Health;

use App\Jobs\SendHealthReminder;
use App\Models\Child;
use App\Models\NotificationDelivery;
use App\Models\User;
use App\Models\Vaccine;
use App\Modules\HealthService\Services\HealthReminderService;
use App\Notifications\Push\PushGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

/** A reminder is sent by exactly one worker, retried when sending fails, and never lost by a crash. */
class ReminderDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private function delivery(): NotificationDelivery
    {
        $user = User::factory()->create();
        $child = Child::create(['user_id' => $user->id, 'child_name' => 'Sara', 'birth_date' => now()->subMonths(2)->toDateString(), 'gender' => 'أنثى']);
        $vaccine = Vaccine::create(['child_id' => $child->id, 'vaccine_name' => 'Polio', 'scheduled_date' => now()->addDay()->toDateString(), 'is_completed' => false]);

        return NotificationDelivery::create(['user_id' => $user->id, 'type' => HealthReminderService::VACCINE, 'reference_type' => 'vaccine',
            'reference_id' => $vaccine->id, 'occurrence' => $vaccine->scheduled_date->toDateString(), 'status' => NotificationDelivery::STATUS_SENDING]);
    }

    private function sent(NotificationDelivery $delivery): int
    {
        return $delivery->user->notifications()->count();
    }

    public function test_only_one_of_several_workers_claims_and_sends_a_reminder(): void
    {
        $delivery = $this->delivery();

        $this->assertTrue(NotificationDelivery::claim($delivery->id));
        $this->assertFalse(NotificationDelivery::claim($delivery->id), 'claimed by the first worker');

        // The job run again (a duplicate queue message, a retry) sends nothing more.
        NotificationDelivery::whereKey($delivery->id)->update(['status' => NotificationDelivery::STATUS_SENDING, 'claimed_at' => null]);
        (new SendHealthReminder($delivery->id))->handle();
        (new SendHealthReminder($delivery->id))->handle();

        $this->assertSame([1, NotificationDelivery::STATUS_SENT], [$this->sent($delivery), $delivery->fresh()->status]);
    }

    public function test_a_failed_send_is_retried_and_recorded_failed_after_the_last_attempt(): void
    {
        $delivery = $this->delivery();
        $this->app->instance(PushGateway::class, new class implements PushGateway {
            public function send(\App\Models\DeviceToken $device, string $title, string $body, array $data = []): bool { throw new RuntimeException('FCM unavailable'); }
        });
        \App\Models\DeviceToken::create(['user_id' => $delivery->user_id, 'token' => 'device-1', 'token_hash' => \App\Models\DeviceToken::hashOf('device-1'), 'platform' => 'android']);

        foreach (range(1, NotificationDelivery::MAX_ATTEMPTS) as $attempt) {
            try {
                (new SendHealthReminder($delivery->id))->handle();
                $threw = false;
            } catch (RuntimeException) {
                $threw = true;
            }
            $this->assertSame($attempt < NotificationDelivery::MAX_ATTEMPTS, $threw, "attempt {$attempt}: the queue retries until the last one");
        }

        $this->assertSame([NotificationDelivery::STATUS_FAILED, NotificationDelivery::MAX_ATTEMPTS], [$delivery->fresh()->status, $delivery->fresh()->attempts]);
        $this->assertFalse(NotificationDelivery::claim($delivery->id));
    }

    public function test_a_reminder_held_by_a_dead_worker_or_a_lost_job_is_sent_later(): void
    {
        Queue::fake();
        $crashed = $this->delivery();
        NotificationDelivery::whereKey($crashed->id)->update(['status' => NotificationDelivery::STATUS_DELIVERING, 'claimed_at' => now()->subMinutes(30), 'attempts' => 1]);
        $lost = $this->delivery();
        NotificationDelivery::whereKey($lost->id)->update(['updated_at' => now()->subMinutes(30)]);
        $fresh = $this->delivery(); // queued a moment ago: its job is still on the way
        $sent = $this->delivery();
        NotificationDelivery::whereKey($sent->id)->update(['status' => NotificationDelivery::STATUS_SENT, 'updated_at' => now()->subDay()]);

        $this->assertSame(2, app(HealthReminderService::class)->recoverStale());
        Queue::assertPushed(SendHealthReminder::class, 2);
        Queue::assertPushed(SendHealthReminder::class, fn ($job) => in_array($job->deliveryId, [$crashed->id, $lost->id], true));

        // The requeued job takes over the dead worker's claim and sends it once.
        (new SendHealthReminder($crashed->id))->handle();
        $this->assertSame([1, NotificationDelivery::STATUS_SENT, 2], [$this->sent($crashed), $crashed->fresh()->status, $crashed->fresh()->attempts]);
        $this->assertSame(0, $this->sent($fresh));
    }

    public function test_a_claim_held_by_a_live_worker_is_not_taken(): void
    {
        $delivery = $this->delivery();
        NotificationDelivery::whereKey($delivery->id)->update(['status' => NotificationDelivery::STATUS_DELIVERING, 'claimed_at' => now()->subMinutes(2), 'attempts' => 1]);

        (new SendHealthReminder($delivery->id))->handle();
        $this->assertSame(0, app(HealthReminderService::class)->recoverStale());

        $this->assertSame([0, NotificationDelivery::STATUS_DELIVERING], [$this->sent($delivery), $delivery->fresh()->status]);
    }
}
