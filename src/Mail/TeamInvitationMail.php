<?php

namespace LaravelMonitor\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use LaravelMonitor\Models\MonitorInvitation;

class TeamInvitationMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public MonitorInvitation $invitation,
        public string $plainToken,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'You’ve been invited to '.config('app.name', 'Laravel').' Monitor',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'monitor::mail.invitation',
            with: [
                'inviterName' => $this->invitation->invitedBy?->name ?? 'A team member',
                'role' => $this->invitation->role,
                'acceptUrl' => url(trim(config('monitor.path', 'monitor'), '/').'/invitations/'.$this->plainToken),
            ],
        );
    }
}
