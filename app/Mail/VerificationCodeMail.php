<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

class VerificationCodeMail extends Mailable
{
    public function __construct(public string $code, public int $ttl) {}

    public function build(): static
    {
        return $this->subject('Подтвердите аккаунт Homeostat')
            ->text('emails.verification-code');
    }
}
