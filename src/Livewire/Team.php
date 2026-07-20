<?php

namespace LaravelMonitor\Livewire;

use Illuminate\Support\Facades\Auth;
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

    public function changeRole(int $memberId, string $role): void
    {
        $actor = $this->actor();

        if (! $actor->isOwner()) {
            abort(403);
        }

        if (! in_array($role, ['admin', 'viewer'], true)) {
            return;
        }

        $member = MonitorUser::query()->find($memberId);

        if ($member === null || $member->id === $actor->id) {
            return;
        }

        $member->update(['role' => $role]);
    }

    public function removeMember(int $memberId): void
    {
        $actor = $this->actor();

        if (! $actor->isOwner()) {
            abort(403);
        }

        if ($memberId === $actor->id) {
            abort(403);
        }

        MonitorUser::query()->find($memberId)?->delete();
    }

    public function leave(): void
    {
        $actor = $this->actor();

        if ($actor->isOwner() && MonitorUser::query()->where('role', 'owner')->count() <= 1) {
            $this->addError('leave', 'Transfer ownership to someone else before leaving — a team always needs an owner.');

            return;
        }

        $actor->delete();

        Auth::guard(MonitorUser::guardName())->logout();

        $this->redirectRoute('monitor.login');
    }

    public function transferOwnership(int $memberId): void
    {
        $actor = $this->actor();

        if (! $actor->isOwner()) {
            abort(403);
        }

        $newOwner = MonitorUser::query()->find($memberId);

        if ($newOwner === null || $newOwner->id === $actor->id) {
            return;
        }

        $newOwner->update(['role' => 'owner']);
        $actor->update(['role' => 'admin']);
    }

    protected function actor(): MonitorUser
    {
        return request()->user(MonitorUser::guardName());
    }
}
