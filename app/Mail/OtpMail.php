<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OtpMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $code,
        public int $minutes,
        public string $purpose = 'register',
    ) {}

    public function envelope(): Envelope
    {
       $subject = match ($this->purpose) {
            'reset' => 'UltraPay password reset code',
            'pin_reset' => 'UltraPay PIN reset code',
            default => 'Aapka UltraPay verification code',
        };

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.otp');
    }
}