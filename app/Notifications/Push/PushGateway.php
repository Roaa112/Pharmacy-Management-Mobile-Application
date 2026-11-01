<?php

namespace App\Notifications\Push;

use App\Models\DeviceToken;

/** Sends one push message to one device. Return false when the provider rejected it. */
interface PushGateway
{
    public function send(DeviceToken $device, string $title, string $body, array $data = []): bool;
}
