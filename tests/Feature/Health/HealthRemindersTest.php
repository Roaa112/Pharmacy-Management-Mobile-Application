<?php

namespace Tests\Feature\Health;

use App\Jobs\SendHealthReminder;
use App\Models\Child;
use App\Models\DeviceToken;
use App\Models\Medication;
use App\Models\NotificationDelivery;
use App\Models\Setting;
use App\Models\User;
use App\Models\Vaccine;
use App\Modules\HealthService\Services\HealthReminderService;
use App\Notifications\Push\PushGateway;
use App\Support\StoreClock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class HealthRemindersTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private array $pushed = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-01 09:00:00');
        $this->user = User::factory()->create();

        // Record pushes instead of sending them.
        $this->app->instance(PushGateway::class, new class($this->pushed) implements PushGateway {
            public function __construct(private array &$log) {}
            public function send(DeviceToken $device, string $title, string $body, array $data = []): bool
            {
                $this->log[] = compact('title', 'body', 'data');
                return true;
            }
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function vaccineInDays(int $days, int $lead = 3, bool $completed = false): Vaccine
    {
        $child = Child::create(['user_id' => $this->user->id, 'child_name' => 'Ali', 'gender' => 'ذكر', 'birth_date' => '2026-01-01']);

        return Vaccine::create(['child_id' => $child->id, 'vaccine_name' => 'لقاح', 'vaccine_name_en' => 'Shot', 'scheduled_date' => StoreClock::now()->addDays($days)->toDateString(), 'reminder_days_before' => $lead, 'is_completed' => $completed]);
    }

    private function device(string $locale = 'ar'): void
    {
        DeviceToken::create(['user_id' => $this->user->id, 'token' => 'tok-'.$locale, 'token_hash' => DeviceToken::hashOf('tok-'.$locale), 'platform' => 'android', 'locale' => $locale]);
    }

    public function test_vaccine_reminders_go_out_once_inside_the_lead_time(): void
    {
        $this->device('en');
        $due = $this->vaccineInDays(2);
        $this->vaccineInDays(10);          // too early
        $this->vaccineInDays(1, completed: true);

        $service = app(HealthReminderService::class);
        $this->assertSame(1, $service->queueVaccineReminders());
        $this->assertSame(0, $service->queueVaccineReminders(), 'never twice');

        $this->assertCount(1, $this->pushed);
        $this->assertSame('Vaccination reminder', $this->pushed[0]['title']);
        $this->assertSame((string) $due->id, (string) $this->pushed[0]['data']['vaccine_id']);

        $delivery = NotificationDelivery::firstOrFail();
        $this->assertSame('sent', $delivery->status);
        $this->assertSame(1, $delivery->devices);
        $this->assertSame(1, $this->user->notifications()->count(), 'kept in the notification history');
    }

    public function test_reminders_respect_the_setting_and_a_vaccine_given_meanwhile(): void
    {
        $vaccine = $this->vaccineInDays(1);
        Setting::current()->update(['vaccine_reminders_enabled' => false]);
        $this->assertSame(0, app(HealthReminderService::class)->queueVaccineReminders());

        Setting::current()->update(['vaccine_reminders_enabled' => true]);
        Queue::fake();
        app(HealthReminderService::class)->queueVaccineReminders();
        Queue::assertPushed(SendHealthReminder::class, 1);

        // Given before the queued job ran: skipped, and running the job again changes nothing.
        $vaccine->update(['is_completed' => true]);
        $job = new SendHealthReminder(NotificationDelivery::firstOrFail()->id);
        $job->handle();
        $job->handle();
        $this->assertSame('skipped', NotificationDelivery::first()->status);
        $this->assertCount(0, $this->pushed);
    }

    public function test_medication_reminders_for_doses_just_due(): void
    {
        Setting::current()->update(['medication_reminders_enabled' => true]);
        $this->device();
        $medication = Medication::create(['user_id' => $this->user->id, 'name' => 'Metformin', 'quantity_per_time' => 1, 'repeat_type' => 'every_day']);
        $medication->times()->create(['time' => StoreClock::now()->subMinutes(5)->format('H:i:s')]);
        $medication->times()->create(['time' => StoreClock::now()->addHour()->format('H:i:s')]);

        $service = app(HealthReminderService::class);
        $this->assertSame(1, $service->queueMedicationReminders());
        $this->assertSame(0, $service->queueMedicationReminders());
        $this->assertStringContainsString('Metformin', $this->pushed[0]['body']);
    }

    public function test_device_registration_and_notification_history_api(): void
    {
        Sanctum::actingAs($this->user);

        $this->postJson('/api/user/devices', ['token' => 'abc', 'platform' => 'ios', 'locale' => 'en'])->assertCreated();
        $this->postJson('/api/user/devices', ['token' => 'abc', 'platform' => 'ios'])->assertCreated();
        $this->assertSame(1, DeviceToken::count());
        $this->postJson('/api/user/devices', ['token' => 'abc', 'platform' => 'windows-phone'])->assertStatus(422);

        $this->vaccineInDays(1);
        $this->artisan('health:send-reminders --vaccines')->assertSuccessful();
        $this->getJson('/api/user/notifications')->assertOk()->assertJsonPath('data.0.kind', 'vaccine')->assertJsonPath('meta.total', 1);

        $this->deleteJson('/api/user/devices', ['token' => 'abc'])->assertOk();
        $this->assertSame(0, DeviceToken::count());
    }
}
