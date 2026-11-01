<?php

namespace App\Notifications\Push;

use App\Models\DeviceToken;
use Illuminate\Support\Facades\Log;

/** PUSH_DRIVER=log (default): writes the message to the log instead of sending it. */
class LogPushGateway implements PushGateway
{
    public function send(DeviceToken $device, string $title, string $body, array $data = []): bool
    {
        Log::info('Push notification (log driver)', ['device' => $device->id, 'title' => $title, 'data' => $data]);

        return true;
    }
}
