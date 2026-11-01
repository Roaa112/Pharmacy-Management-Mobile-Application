<?php

namespace App\Notifications;

use App\Models\User;
use App\Notifications\Push\PushChannel;
use Illuminate\Notifications\Notification;

/**
 * A health reminder (vaccination, medication, ...). Stored in the notifications table (the app's
 * notification history) and pushed to the user's devices. Text is built in the device's language
 * when the reminder is created.
 */
class HealthReminder extends Notification
{
    public function __construct(
        public readonly string $kind,
        public readonly string $title,
        public readonly string $body,
        public readonly array $data = [],
    ) {
    }

    public function via(User $notifiable): array
    {
        return ['database', PushChannel::class];
    }

    public function toArray(User $notifiable): array
    {
        return ['kind' => $this->kind, 'title' => $this->title, 'body' => $this->body] + $this->data;
    }

    public function toPush(User $notifiable): array
    {
        return ['title' => $this->title, 'body' => $this->body, 'data' => ['kind' => $this->kind] + $this->data];
    }
}
