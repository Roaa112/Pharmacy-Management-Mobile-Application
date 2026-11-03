<?php

namespace App\Jobs;

use App\Models\ContactMessageReply;
use App\Notifications\ContactMessageReplied;
use App\Notifications\Push\PushChannel;
use Illuminate\Foundation\Bus\Dispatchable;
use Throwable;

/**
 * Sends a pharmacy reply to the customer: the app's notification history and a push to each of
 * their devices. Dispatched after the response (no queue worker needed) and records what happened
 * on the reply, so the dashboard shows whether it arrived. Runs once per claim (sent_at);
 * "Resend" in the dashboard clears the claim.
 */
class DeliverContactReply
{
    use Dispatchable;

    public function __construct(public int $replyId)
    {
    }

    public function handle(PushChannel $push): void
    {
        if (!ContactMessageReply::whereKey($this->replyId)->where('from_customer', false)->whereNull('sent_at')->update(['sent_at' => now()])) {
            return; // a customer reply, already sent, or deleted
        }

        $reply = ContactMessageReply::with('contactMessage.user.deviceTokens')->findOrFail($this->replyId);
        $user = $reply->contactMessage->user;

        if (!$user) {
            $reply->update(['push_devices' => 0, 'delivery_error' => 'The customer account no longer exists.']);

            return;
        }

        $notification = new ContactMessageReplied($reply, $user->deviceTokens->first()?->locale === 'en' ? 'en' : 'ar');

        try {
            $user->notifyNow($notification, ['database']);
            $reply->update(['push_devices' => $user->deviceTokens->isEmpty() ? 0 : $push->send($user, $notification), 'delivery_error' => null]);
        } catch (Throwable $e) {
            report($e);
            $reply->update(['delivery_error' => mb_substr($e->getMessage(), 0, 250)]);
        }
    }
}
