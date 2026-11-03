<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
use App\Models\AdminAuditLog;
use App\Models\ContactMessage;
use App\Models\ContactMessageReply;
use App\Models\DeviceToken;
use App\Models\User;
use App\Notifications\Push\PushGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

/** The pharmacy replies to contact messages from the dashboard and the reply reaches the customer. */
class ContactMessageRepliesTest extends TestCase
{
    use RefreshDatabase;

    private Admin $admin;
    private User $customer;
    private ContactMessage $message;
    public array $pushed = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(PushGateway::class, new class($this) implements PushGateway {
            public function __construct(private ContactMessageRepliesTest $test)
            {
            }

            public function send(DeviceToken $device, string $title, string $body, array $data = []): bool
            {
                $this->test->pushed[] = compact('title', 'body', 'data') + ['device' => $device->id];

                return true;
            }
        });

        $this->admin = Admin::create(['name' => 'Sara Admin', 'email' => 'admin@example.com', 'password' => 'secret-password']);
        $this->customer = User::factory()->create(['name' => 'Omar', 'email' => 'omar-account@example.com']);
        $this->message = ContactMessage::create(['user_id' => $this->customer->id, 'name' => 'Omar', 'email' => 'omar@example.com', 'message' => 'Do you deliver to 6th of October?']);
    }

    private function device(string $locale = 'ar'): DeviceToken
    {
        return DeviceToken::create(['user_id' => $this->customer->id, 'token' => 'tok-'.$locale, 'token_hash' => DeviceToken::hashOf('tok-'.$locale), 'platform' => 'android', 'locale' => $locale]);
    }

    public function test_a_reply_is_pushed_and_stored_in_the_app_notifications(): void
    {
        $this->device('en');

        $this->actingAs($this->admin, 'admin')->from('/dashboard/contacts')
            ->post("/dashboard/contact-messages/{$this->message->id}/replies", ['body' => 'Yes, delivery takes one day.'])
            ->assertRedirect('/dashboard/contacts');

        $reply = ContactMessageReply::sole();
        $this->assertSame($this->admin->id, $reply->admin_id);
        $this->assertNotNull($reply->sent_at);
        $this->assertSame(1, $reply->push_devices);
        $this->assertNull($reply->delivery_error);
        $this->assertSame(ContactMessage::STATUS_REPLIED, $this->message->fresh()->status);

        $this->assertCount(1, $this->pushed);
        $this->assertSame('The pharmacy replied to your message', $this->pushed[0]['title']);
        $this->assertSame(['kind' => 'contact_reply', 'contact_message_id' => $this->message->id, 'reply_id' => $reply->id], $this->pushed[0]['data']);

        $notification = $this->customer->notifications()->sole();
        $this->assertSame('contact_reply', $notification->data['kind']);
        $this->assertSame('Yes, delivery takes one day.', $notification->data['body']);

        $this->assertTrue(AdminAuditLog::where('action', 'contact_message.replied')->exists());
    }

    public function test_the_dashboard_shows_the_conversation_and_how_the_reply_was_delivered(): void
    {
        $this->device();
        $this->actingAs($this->admin, 'admin')->post("/dashboard/contact-messages/{$this->message->id}/replies", ['body' => 'Yes, we do.']);

        $this->actingAs($this->admin, 'admin')->get('/dashboard/contacts')->assertOk()
            ->assertSee('Yes, we do.')->assertSee('Sara Admin')
            ->assertSee('Push notification · 1 device')->assertSee('Replied');

        $this->actingAs($this->admin, 'admin')->get('/dashboard/contacts?status=open')->assertOk()->assertDontSee('Yes, we do.');
    }

    public function test_without_a_device_the_reply_is_still_in_the_app_notifications(): void
    {
        $this->actingAs($this->admin, 'admin')->post("/dashboard/contact-messages/{$this->message->id}/replies", ['body' => 'Hello']);

        $reply = ContactMessageReply::sole();
        $this->assertSame(0, $reply->push_devices);
        $this->assertNull($reply->delivery_error);
        $this->assertCount(1, $this->customer->notifications);
        $this->assertCount(0, $this->pushed);
    }

    public function test_a_failed_channel_is_recorded_and_the_reply_can_be_resent(): void
    {
        $this->device();
        $this->app->instance(PushGateway::class, new class implements PushGateway {
            public function send(DeviceToken $device, string $title, string $body, array $data = []): bool
            {
                throw new RuntimeException('FCM credentials are missing');
            }
        });

        $this->actingAs($this->admin, 'admin')->post("/dashboard/contact-messages/{$this->message->id}/replies", ['body' => 'Hello']);

        $reply = ContactMessageReply::sole();
        $this->assertStringContainsString('FCM credentials are missing', $reply->delivery_error);
        $this->assertNull($reply->push_devices);
        $this->assertTrue($reply->canResend());

        $this->app->instance(PushGateway::class, new class implements PushGateway {
            public function send(DeviceToken $device, string $title, string $body, array $data = []): bool
            {
                return true;
            }
        });
        $this->actingAs($this->admin, 'admin')->post("/dashboard/contact-messages/{$this->message->id}/replies/{$reply->id}/resend")->assertRedirect();

        $reply->refresh();
        $this->assertNull($reply->delivery_error);
        $this->assertSame(1, $reply->push_devices);
    }

    public function test_a_delivered_reply_cannot_be_resent(): void
    {
        $this->actingAs($this->admin, 'admin')->post("/dashboard/contact-messages/{$this->message->id}/replies", ['body' => 'Hello']);
        $reply = ContactMessageReply::sole();

        $this->actingAs($this->admin, 'admin')->post("/dashboard/contact-messages/{$this->message->id}/replies/{$reply->id}/resend")->assertNotFound();
    }

    public function test_replying_needs_the_reply_permission(): void
    {
        $editor = Admin::create(['name' => 'Editor', 'email' => 'editor@example.com', 'password' => 'secret-password']);
        $editor->forceFill(['role' => 'content_editor'])->save();

        $this->actingAs($editor, 'admin')->post("/dashboard/contact-messages/{$this->message->id}/replies", ['body' => 'Hello'])->assertForbidden();
        $this->assertSame(0, ContactMessageReply::count());
    }

    public function test_the_customer_reads_the_conversation_and_answers_back(): void
    {
        $this->device();
        $this->actingAs($this->admin, 'admin')->post("/dashboard/contact-messages/{$this->message->id}/replies", ['body' => 'Yes, we do.']);
        Sanctum::actingAs($this->customer);

        $this->getJson('/api/user/contact-messages')->assertOk()
            ->assertJsonPath('data.0.id', $this->message->id)
            ->assertJsonPath('data.0.status', 'replied')
            ->assertJsonPath('data.0.replies.0.from', 'pharmacy')
            ->assertJsonPath('data.0.replies.0.body', 'Yes, we do.')
            ->assertJsonMissing(['admin_id' => $this->admin->id]);

        $this->postJson("/api/user/contact-messages/{$this->message->id}/replies", ['body' => 'Great, thanks!'])->assertCreated()
            ->assertJsonPath('data.replies.1.from', 'customer');

        $this->assertSame(ContactMessage::STATUS_OPEN, $this->message->fresh()->status);
        $this->assertCount(1, $this->pushed, 'a customer reply is not pushed to the customer');
    }

    public function test_customers_only_see_their_own_conversations(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/user/contact-messages')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/user/contact-messages/{$this->message->id}")->assertNotFound();
        $this->postJson("/api/user/contact-messages/{$this->message->id}/replies", ['body' => 'Hi'])->assertNotFound();
        $this->assertSame(0, ContactMessageReply::count());
    }
}
