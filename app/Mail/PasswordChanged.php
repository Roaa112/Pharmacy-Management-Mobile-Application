<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/** Tells the owner their password was changed, so a change they did not make does not go unnoticed. */
class PasswordChanged extends Mailable implements ShouldQueue, ShouldBeEncrypted
{
    use Queueable, SerializesModels;

    public function __construct(public string $name)
    {
    }

    public function build()
    {
        return $this->subject('Your password was changed')
                    ->view('User.auth.emails.password-changed');
    }
}
