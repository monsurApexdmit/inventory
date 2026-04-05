<?php

namespace App\Mail\Auth;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class VerificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $verificationLink;

    public function __construct(
        public readonly string $fullName,
        string $token,
    ) {
        $this->verificationLink = config('app.frontend_url') . '/auth/verify-email?token=' . $token;
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Verify your email address');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.auth.verification');
    }
}
