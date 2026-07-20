<?php

namespace LaravelMonitor\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PasswordResetMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public string $plainToken,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Reset your '.config('app.name', 'Laravel').' Monitor password',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'monitor::mail.password-reset',
            with: [
                'resetUrl' => url(trim(config('monitor.path', 'monitor'), '/').'/reset-password/'.$this->plainToken),
            ],
        );
    }
}
