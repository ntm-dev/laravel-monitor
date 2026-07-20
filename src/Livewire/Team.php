<?php

namespace LaravelMonitor\Livewire;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use LaravelMonitor\Mail\EmailChangeVerificationMail;
use LaravelMonitor\Mail\TeamInvitationMail;
use LaravelMonitor\Models\MonitorEmailChange;
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
        $actor = $this->actor();

        $pendingEmailChanges = MonitorEmailChange::query()
            ->whereNotNull('verified_at')
            ->with('user')
            ->orderByDesc('created_at')
            ->get()
            ->filter(fn (MonitorEmailChange $emailChange) => $emailChange->user !== null)
            ->each(function (MonitorEmailChange $emailChange) use ($actor) {
                $emailChange->canDecide = $this->canDecideEmailChange($actor, $emailChange->user);
            })
            ->values();

        return [
            'members' => MonitorUser::query()->orderBy('created_at')->get(),
            'pendingInvitations' => MonitorInvitation::query()
                ->where('expires_at', '>', now())
                ->orderByDesc('created_at')
                ->get(),
            'pendingEmailChanges' => $pendingEmailChanges,
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

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->addError('email', 'Please enter a valid email address.');

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

    public function requestEmailChange(string $newEmail): void
    {
        $actor = $this->actor();

        if (! filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
            $this->addError('newEmail', 'Please enter a valid email address.');

            return;
        }

        if (MonitorUser::query()->where('email', $newEmail)->where('id', '!=', $actor->id)->exists()) {
            $this->addError('newEmail', 'This email is already in use.');

            return;
        }

        ['plainToken' => $plainToken] = MonitorEmailChange::createFor($actor, $newEmail);

        Mail::to($newEmail)->send(new EmailChangeVerificationMail($plainToken));
    }

    public function approveEmailChange(int $emailChangeId): void
    {
        $actor = $this->actor();
        $emailChange = MonitorEmailChange::query()->find($emailChangeId);

        if ($emailChange === null || ! $emailChange->isVerified()) {
            return;
        }

        $requester = $emailChange->user;

        if ($requester === null) {
            $emailChange->delete();

            return;
        }

        if (! $this->canDecideEmailChange($actor, $requester)) {
            abort(403);
        }

        if (MonitorUser::query()->where('email', $emailChange->new_email)->where('id', '!=', $requester->id)->exists()) {
            $this->addError('emailChange', 'That email is no longer available.');

            return;
        }

        $requester->update(['email' => $emailChange->new_email]);
        $emailChange->delete();
    }

    public function rejectEmailChange(int $emailChangeId): void
    {
        $actor = $this->actor();
        $emailChange = MonitorEmailChange::query()->find($emailChangeId);

        if ($emailChange === null || ! $emailChange->isVerified()) {
            return;
        }

        if ($emailChange->user !== null && ! $this->canDecideEmailChange($actor, $emailChange->user)) {
            abort(403);
        }

        $emailChange->delete();
    }

    protected function canDecideEmailChange(MonitorUser $actor, MonitorUser $requester): bool
    {
        return match ($requester->role) {
            'admin' => $actor->isOwner(),
            'viewer' => $actor->canManageTeam(),
            default => false,
        };
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
