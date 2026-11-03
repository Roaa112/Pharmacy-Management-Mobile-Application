<?php

namespace App\Notifications;

use App\Models\ContactMessageReply;
use App\Models\User;
use App\Notifications\Push\PushChannel;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * The pharmacy answered a contact message. Stored in the notifications table (the app's
 * notification history) and pushed to the customer's devices; `contact_message_id` lets the app
 * open the conversation.
 */
class ContactMessageReplied extends Notification
{
    public function __construct(public readonly ContactMessageReply $reply, public readonly string $language)
    {
    }

    public function via(User $notifiable): array
    {
        return ['database', PushChannel::class];
    }

    public function toArray(User $notifiable): array
    {
        return ['kind' => 'contact_reply', 'title' => $this->title(), 'body' => $this->reply->body] + $this->data();
    }

    public function toPush(User $notifiable): array
    {
        return ['title' => $this->title(), 'body' => Str::limit($this->reply->body, 180), 'data' => ['kind' => 'contact_reply'] + $this->data()];
    }

    private function title(): string
    {
        return __('contact.reply_title', [], $this->language);
    }

    private function data(): array
    {
        return ['contact_message_id' => $this->reply->contact_message_id, 'reply_id' => $this->reply->id];
    }
}
