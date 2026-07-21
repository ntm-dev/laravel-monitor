<?php

namespace LaravelMonitor\Livewire;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use LaravelMonitor\Mail\EmailChangeVerificationMail;
use LaravelMonitor\Mail\TeamInvitationMail;
use LaravelMonitor\Models\MonitorEmailChange;
use LaravelMonitor\Models\MonitorInvitation;
use LaravelMonitor\Models\MonitorUser;
use LaravelMonitor\Models\MonitorWebauthnCredential;
use LaravelMonitor\Support\OptionalAuthMethod;
use PragmaRX\Google2FA\Google2FA;

class Team extends Card
{
    protected function view(): string
    {
        return 'monitor::livewire.team';
    }

    public string $memberSearch = '';

    public string $memberRoleFilter = 'all';

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

        $roleOrder = ['owner' => 2, 'admin' => 1, 'viewer' => 0];

        $members = MonitorUser::query()
            ->when($this->memberRoleFilter !== 'all', fn ($query) => $query->where('role', $this->memberRoleFilter))
            ->when($this->memberSearch !== '', function ($query) {
                $query->where(function ($query) {
                    $query->where('name', 'like', '%'.$this->memberSearch.'%')
                        ->orWhere('email', 'like', '%'.$this->memberSearch.'%');
                });
            })
            ->get()
            ->sort(function (MonitorUser $a, MonitorUser $b) use ($roleOrder) {
                $roleComparison = ($roleOrder[$b->role] ?? -1) <=> ($roleOrder[$a->role] ?? -1);

                return $roleComparison !== 0 ? $roleComparison : $b->id <=> $a->id;
            })
            ->values();

        return [
            'members' => $members,
            'pendingInvitations' => MonitorInvitation::query()
                ->where('expires_at', '>', now())
                ->orderByDesc('created_at')
                ->get(),
            'pendingEmailChanges' => $pendingEmailChanges,
            'passkeys' => MonitorWebauthnCredential::query()->where('user_id', $actor->id)->get(),
            'soleOwner' => $actor->isOwner() && MonitorUser::query()->where('role', 'owner')->count() <= 1,
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
            $this->addError('email', __('monitor::messages.team.error_invalid_email'));

            return;
        }

        if (MonitorUser::query()->where('email', $email)->exists()) {
            $this->addError('email', __('monitor::messages.team.error_email_already_member'));

            return;
        }

        ['invitation' => $invitation, 'plainToken' => $plainToken] = MonitorInvitation::createFor($email, $role, $actor);

        Mail::to($email)->send(new TeamInvitationMail($invitation, $plainToken));

        $this->dispatch('member-invited');
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

    public bool $editingEmail = false;

    public bool $emailChangeRequested = false;

    public function startEditingEmail(): void
    {
        $this->editingEmail = true;
        $this->emailChangeRequested = false;
        $this->resetErrorBag(['newEmail', 'emailPassword']);
    }

    public function cancelEditingEmail(): void
    {
        $this->editingEmail = false;
        $this->resetErrorBag(['newEmail', 'emailPassword']);
    }

    public function requestEmailChange(string $newEmail, string $currentPassword): void
    {
        $actor = $this->actor();

        if (! Hash::check($currentPassword, $actor->password)) {
            $this->addError('emailPassword', __('monitor::messages.team.error_wrong_password'));

            return;
        }

        if (! filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
            $this->addError('newEmail', __('monitor::messages.team.error_invalid_email'));

            return;
        }

        if (MonitorUser::query()->where('email', $newEmail)->where('id', '!=', $actor->id)->exists()) {
            $this->addError('newEmail', __('monitor::messages.team.error_email_already_in_use'));

            return;
        }

        ['plainToken' => $plainToken] = MonitorEmailChange::createFor($actor, $newEmail);

        Mail::to($newEmail)->send(new EmailChangeVerificationMail($plainToken));

        $this->editingEmail = false;
        $this->emailChangeRequested = true;
    }

    public bool $passwordChanged = false;

    public function changePassword(string $currentPassword, string $newPassword, string $newPasswordConfirmation): void
    {
        $actor = $this->actor();
        $this->passwordChanged = false;

        if (! Hash::check($currentPassword, $actor->password)) {
            $this->addError('currentPassword', __('monitor::messages.team.error_wrong_password'));

            return;
        }

        if (strlen($newPassword) < 8) {
            $this->addError('newPassword', __('monitor::messages.team.error_password_too_short'));

            return;
        }

        if ($newPassword !== $newPasswordConfirmation) {
            $this->addError('newPassword', __('monitor::messages.team.error_password_confirmation_mismatch'));

            return;
        }

        $actor->update(['password' => Hash::make($newPassword)]);

        $this->passwordChanged = true;
        $this->dispatch('password-changed');
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
            $this->addError('emailChange', __('monitor::messages.team.error_email_no_longer_available'));

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

    public ?string $totpSecret = null;

    public function startEnrollingTotp(): void
    {
        if (! OptionalAuthMethod::totpAvailable()) {
            return;
        }

        $this->resetErrorBag('totp');
        $this->totpSecret = (new Google2FA())->generateSecretKey();
    }

    public function cancelEnrollingTotp(): void
    {
        $this->totpSecret = null;
        $this->resetErrorBag('totp');
    }

    public function confirmTotp(string $code): void
    {
        if ($this->totpSecret === null) {
            return;
        }

        $google2fa = new Google2FA();

        if (! $google2fa->verifyKey($this->totpSecret, $code)) {
            $this->addError('totp', __('monitor::messages.team.error_totp_code_mismatch'));

            return;
        }

        $actor = $this->actor();
        $recoveryCodes = collect(range(1, 8))
            ->map(fn () => Str::upper(Str::random(10)))
            ->values();

        $actor->update([
            'totp_secret' => $this->totpSecret,
            'totp_enabled_at' => now(),
            'totp_recovery_codes' => $recoveryCodes->map(fn (string $code) => Hash::make($code))->all(),
        ]);

        $this->totpSecret = null;
        $this->dispatch('totp-enabled', recoveryCodes: $recoveryCodes->all());
    }

    public function disableTotp(string $currentPassword): void
    {
        $actor = $this->actor();

        if (! Hash::check($currentPassword, $actor->password)) {
            $this->addError('totp', __('monitor::messages.team.error_wrong_password'));

            return;
        }

        $actor->update(['totp_secret' => null, 'totp_enabled_at' => null, 'totp_recovery_codes' => null]);

        $this->dispatch('totp-disabled');
    }

    public function disableMemberTotp(int $memberId): void
    {
        $actor = $this->actor();

        if (! $actor->isOwner()) {
            abort(403);
        }

        if ($memberId === $actor->id) {
            abort(403);
        }

        MonitorUser::query()->find($memberId)?->update([
            'totp_secret' => null,
            'totp_enabled_at' => null,
            'totp_recovery_codes' => null,
        ]);
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
            $this->addError('leave', __('monitor::messages.team.error_sole_owner_cannot_leave'));

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

    public function removePasskey(int $credentialId): void
    {
        $actor = $this->actor();

        MonitorWebauthnCredential::query()
            ->where('id', $credentialId)
            ->where('user_id', $actor->id)
            ->delete();
    }

    protected function actor(): MonitorUser
    {
        return request()->user(MonitorUser::guardName());
    }
}
