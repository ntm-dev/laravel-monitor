<?php

namespace LaravelMonitor\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class EmailChangeVerificationMail extends Mailable
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
            subject: 'Verify your new '.config('app.name', 'Laravel').' Monitor email',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'monitor::mail.email-change-verification',
            with: [
                'verifyUrl' => url(trim(config('monitor.path', 'monitor'), '/').'/email-changes/'.$this->plainToken),
            ],
        );
    }
}
