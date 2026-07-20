<?php

namespace LaravelMonitor\Livewire;

use Illuminate\Support\Facades\Mail;
use LaravelMonitor\Mail\TeamInvitationMail;
use LaravelMonitor\Models\MonitorInvitation;
use LaravelMonitor\Models\MonitorUser;

class Team extends Card
{
    protected function view(): string
    {
        return 'monitor::livewire.team';
    }

    protected function data(): array
    {
        return [
            'members' => MonitorUser::query()->orderBy('created_at')->get(),
            'pendingInvitations' => MonitorInvitation::query()
                ->where('expires_at', '>', now())
                ->orderByDesc('created_at')
                ->get(),
        ];
    }

    public function invite(string $email, string $role): void
    {
        $actor = $this->actor();

        if (! $actor->canManageTeam()) {
            abort(403);
        }

        if (! in_array($role, ['admin', 'viewer'], true)) {
            return;
        }

        if (MonitorUser::query()->where('email', $email)->exists()) {
            $this->addError('email', 'This email is already a member.');

            return;
        }

        ['invitation' => $invitation, 'plainToken' => $plainToken] = MonitorInvitation::createFor($email, $role, $actor);

        Mail::to($email)->send(new TeamInvitationMail($invitation, $plainToken));
    }

    public function cancelInvite(int $invitationId): void
    {
        $actor = $this->actor();
        $invitation = MonitorInvitation::query()->find($invitationId);

        if ($invitation === null) {
            return;
        }

        if (! $actor->isOwner() && $invitation->invited_by !== $actor->id) {
            abort(403);
        }

        $invitation->delete();
    }

    protected function actor(): MonitorUser
    {
        return request()->user(MonitorUser::guardName());
    }
}
