<?php

namespace App\Notifications\Push;

use App\Models\User;
use Illuminate\Notifications\Notification;

/**
 * Notification channel that sends a notification's toPush() message to every registered device
 * of the user. Returns how many devices accepted it.
 */
class PushChannel
{
    public function __construct(private PushGateway $gateway)
    {
    }

    public function send(User $notifiable, Notification $notification): int
    {
        $message = $notification->toPush($notifiable);
        $sent = 0;

        foreach ($notifiable->deviceTokens as $device) {
            if ($this->gateway->send($device, $message['title'], $message['body'], $message['data'] ?? [])) {
                $sent++;
            }
        }

        return $sent;
    }
}
