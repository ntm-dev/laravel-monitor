@php
    use LaravelMonitor\Support\Icons;

    $actor = request()->user(\LaravelMonitor\Models\MonitorUser::guardName());
    $roleBadge = fn (string $role) => match ($role) {
        'owner' => 'border-blue-200 dark:border-blue-500/30 bg-blue-50 dark:bg-blue-500/10 text-blue-600 dark:text-blue-400',
        'admin' => 'border-amber-200 dark:border-amber-500/30 bg-amber-50 dark:bg-amber-500/10 text-amber-600 dark:text-amber-400',
        default => 'border-neutral-200 dark:border-neutral-700 bg-neutral-100/80 dark:bg-neutral-800/80 text-neutral-500 dark:text-neutral-400',
    };
@endphp
<div wire:poll.{{ $refresh }}s>
    <x-monitor::section :icon="Icons::TEAM" title="Team">
        @if ($actor->canManageTeam())
            <x-monitor::card class="p-4">
                <p class="font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400">Invite a member</p>
                <form wire:submit="invite($refs.email.value, $refs.role.value)" class="mt-3 flex flex-wrap items-end gap-2" x-data>
                    <div class="min-w-0 flex-1">
                        <label class="block font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400">Email</label>
                        <input type="email" x-ref="email" required
                               class="mt-1 w-full rounded-md border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 px-2 py-1.5 text-sm text-neutral-900 dark:text-neutral-100 focus:outline-none">
                    </div>
                    <div>
                        <label class="block font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400">Role</label>
                        <select x-ref="role" class="mt-1 rounded-md border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 px-2 py-1.5 text-sm text-neutral-900 dark:text-neutral-100 focus:outline-none">
                            <option value="viewer">Viewer</option>
                            <option value="admin">Admin</option>
                        </select>
                    </div>
                    <button type="submit" class="h-8 rounded-md bg-blue-600 px-3 text-sm font-medium text-white hover:bg-blue-500">Send invite</button>
                </form>
                @error('email')
                    <p class="mt-2 text-sm text-rose-600 dark:text-rose-400">{{ $message }}</p>
                @enderror
            </x-monitor::card>
        @endif

        <x-monitor::card class="p-4">
            <p class="font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400">Change your email</p>
            <form wire:submit="requestEmailChange($refs.newEmail.value)" class="mt-3 flex flex-wrap items-end gap-2" x-data>
                <div class="min-w-0 flex-1">
                    <label class="block font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400">New email</label>
                    <input type="email" x-ref="newEmail" required
                           class="mt-1 w-full rounded-md border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 px-2 py-1.5 text-sm text-neutral-900 dark:text-neutral-100 focus:outline-none">
                </div>
                <button type="submit" class="h-8 rounded-md bg-blue-600 px-3 text-sm font-medium text-white hover:bg-blue-500">Send verification email</button>
            </form>
            @error('newEmail')
                <p class="mt-2 text-sm text-rose-600 dark:text-rose-400">{{ $message }}</p>
            @enderror
        </x-monitor::card>

        <x-monitor::card class="p-4">
            <p class="font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400">Two-factor authentication</p>
            @if (! \LaravelMonitor\Support\OptionalAuthMethod::totpAvailable())
                <p class="mt-2 text-sm text-neutral-400 dark:text-neutral-500">Install <code class="font-mono text-xs">pragmarx/google2fa bacon/bacon-qr-code</code> to enable this.</p>
            @elseif ($actor->hasTotpEnabled())
                <p class="mt-2 text-sm text-neutral-500 dark:text-neutral-400">Enabled for your account.</p>
            @elseif ($totpSecret === null)
                <button type="button" wire:click="startEnrollingTotp" class="mt-3 h-8 rounded-md bg-blue-600 px-3 text-sm font-medium text-white hover:bg-blue-500">Enable</button>
            @else
                <div class="mt-3">
                    {!! (new BaconQrCode\Writer(new BaconQrCode\Renderer\ImageRenderer(new BaconQrCode\Renderer\RendererStyle\RendererStyle(200), new BaconQrCode\Renderer\Image\SvgImageBackEnd())))->writeString((new PragmaRX\Google2FA\Google2FA())->getQRCodeUrl(config('app.name', 'Laravel'), $actor->email, $totpSecret)) !!}
                    <p class="mt-2 font-mono text-xs text-neutral-500 dark:text-neutral-400">{{ $totpSecret }}</p>
                </div>
                <form wire:submit="confirmTotp($refs.totpCode.value)" class="mt-3 flex flex-wrap items-end gap-2" x-data>
                    <div class="min-w-0 flex-1">
                        <label class="block font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400">Enter the 6-digit code</label>
                        <input type="text" x-ref="totpCode" required inputmode="numeric" pattern="[0-9]*" maxlength="6"
                               class="mt-1 w-full rounded-md border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 px-2 py-1.5 text-sm text-neutral-900 dark:text-neutral-100 focus:outline-none">
                    </div>
                    <button type="submit" class="h-8 rounded-md bg-blue-600 px-3 text-sm font-medium text-white hover:bg-blue-500">Confirm</button>
                </form>
                @error('totp')
                    <p class="mt-2 text-sm text-rose-600 dark:text-rose-400">{{ $message }}</p>
                @enderror
            @endif
        </x-monitor::card>

        <div x-data="{ codes: null }" x-on:totp-enabled.window="codes = $event.detail.recoveryCodes" x-show="codes" x-cloak>
            <x-monitor::card class="mt-4 p-4">
                <p class="font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400">Recovery codes — save these now, they won't be shown again</p>
                <ul class="mt-3 grid grid-cols-2 gap-1 font-mono text-sm text-neutral-700 dark:text-neutral-200">
                    <template x-for="code in codes" :key="code">
                        <li x-text="code"></li>
                    </template>
                </ul>
            </x-monitor::card>
        </div>

        @if ($pendingInvitations->isNotEmpty())
            <div class="mt-4 flex items-center gap-2 px-1 pb-3">
                <h3 class="font-semibold text-neutral-900 dark:text-neutral-100">{{ number_format($pendingInvitations->count()) }} Pending {{ $pendingInvitations->count() === 1 ? 'Invite' : 'Invites' }}</h3>
            </div>
            <x-monitor::card class="p-4">
                <div class="divide-y divide-neutral-100 dark:divide-neutral-800">
                    @foreach ($pendingInvitations as $invitation)
                        <div class="flex items-center gap-3 py-2.5">
                            <span class="min-w-0 flex-1 truncate font-mono text-sm text-neutral-700 dark:text-neutral-200">{{ $invitation->email }}</span>
                            <span class="shrink-0 rounded border px-1.5 py-0.5 font-mono text-[10px] uppercase tracking-tight {{ $roleBadge($invitation->role) }}">{{ $invitation->role }}</span>
                            <span class="shrink-0 font-mono text-xs text-neutral-400 dark:text-neutral-500">expires {{ $invitation->expires_at->diffForHumans() }}</span>
                            @if ($actor->isOwner() || $invitation->invited_by === $actor->id)
                                <button type="button" wire:click="cancelInvite({{ $invitation->id }})"
                                        class="shrink-0 rounded-md border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 px-2 py-1 font-mono text-[10px] uppercase tracking-tight text-neutral-500 dark:text-neutral-400 shadow-sm hover:bg-neutral-50 dark:hover:bg-neutral-800/50">Cancel</button>
                            @endif
                        </div>
                    @endforeach
                </div>
            </x-monitor::card>
        @endif

        @if ($pendingEmailChanges->isNotEmpty())
            <div class="mt-4 flex items-center gap-2 px-1 pb-3">
                <h3 class="font-semibold text-neutral-900 dark:text-neutral-100">{{ number_format($pendingEmailChanges->count()) }} Pending Email {{ $pendingEmailChanges->count() === 1 ? 'Change' : 'Changes' }}</h3>
            </div>
            <x-monitor::card class="p-4">
                <div class="divide-y divide-neutral-100 dark:divide-neutral-800">
                    @foreach ($pendingEmailChanges as $emailChange)
                        <div class="flex items-center gap-3 py-2.5">
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-medium text-neutral-900 dark:text-neutral-100">{{ $emailChange->user->name }}</p>
                                <p class="truncate font-mono text-xs text-neutral-400 dark:text-neutral-500">{{ $emailChange->user->email }} &rarr; {{ $emailChange->new_email }}</p>
                            </div>
                            @if ($emailChange->canDecide)
                                <button type="button" wire:click="approveEmailChange({{ $emailChange->id }})"
                                        class="shrink-0 rounded-md border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 px-2 py-1 font-mono text-[10px] uppercase tracking-tight text-neutral-500 dark:text-neutral-400 shadow-sm hover:bg-neutral-50 dark:hover:bg-neutral-800/50">Approve</button>
                                <button type="button" wire:click="rejectEmailChange({{ $emailChange->id }})" wire:confirm="Reject this email change?"
                                        class="shrink-0 rounded-md border border-rose-200 dark:border-rose-500/30 bg-white dark:bg-neutral-900 px-2 py-1 font-mono text-[10px] uppercase tracking-tight text-rose-600 dark:text-rose-400 shadow-sm hover:bg-rose-50 dark:hover:bg-rose-500/10">Reject</button>
                            @endif
                        </div>
                    @endforeach
                </div>
                @error('emailChange')
                    <p class="mt-3 text-sm text-rose-600 dark:text-rose-400">{{ $message }}</p>
                @enderror
            </x-monitor::card>
        @endif

        <div class="mt-4 flex items-center gap-2 px-1 pb-3">
            <h3 class="font-semibold text-neutral-900 dark:text-neutral-100">{{ number_format($members->count()) }} {{ $members->count() === 1 ? 'Member' : 'Members' }}</h3>
        </div>
        <x-monitor::card class="p-4">
            <div class="divide-y divide-neutral-100 dark:divide-neutral-800">
                @foreach ($members as $member)
                    <div class="flex items-center gap-3 py-2.5">
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium text-neutral-900 dark:text-neutral-100">{{ $member->name }}</p>
                            <p class="truncate font-mono text-xs text-neutral-400 dark:text-neutral-500">{{ $member->email }}</p>
                        </div>
                        @if ($actor->isOwner() && $member->id !== $actor->id)
                            <select wire:change="changeRole({{ $member->id }}, $event.target.value)"
                                    class="shrink-0 rounded-md border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 px-1.5 py-1 font-mono text-[10px] uppercase tracking-tight text-neutral-500 dark:text-neutral-400">
                                <option value="admin" @selected($member->role === 'admin')>Admin</option>
                                <option value="viewer" @selected($member->role === 'viewer')>Viewer</option>
                            </select>
                            <button type="button" wire:click="transferOwnership({{ $member->id }})" wire:confirm="Make {{ $member->name }} the owner? You'll become an admin."
                                    class="shrink-0 rounded-md border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 px-2 py-1 font-mono text-[10px] uppercase tracking-tight text-neutral-500 dark:text-neutral-400 shadow-sm hover:bg-neutral-50 dark:hover:bg-neutral-800/50">Make owner</button>
                            <button type="button" wire:click="removeMember({{ $member->id }})" wire:confirm="Remove {{ $member->name }} from the team?"
                                    class="shrink-0 rounded-md border border-rose-200 dark:border-rose-500/30 bg-white dark:bg-neutral-900 px-2 py-1 font-mono text-[10px] uppercase tracking-tight text-rose-600 dark:text-rose-400 shadow-sm hover:bg-rose-50 dark:hover:bg-rose-500/10">Remove</button>
                        @else
                            <span class="shrink-0 rounded border px-1.5 py-0.5 font-mono text-[10px] uppercase tracking-tight {{ $roleBadge($member->role) }}">{{ $member->role }}</span>
                        @endif
                        @if ($member->id === $actor->id)
                            <button type="button" wire:click="leave" wire:confirm="Leave the team?"
                                    class="shrink-0 rounded-md border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 px-2 py-1 font-mono text-[10px] uppercase tracking-tight text-neutral-500 dark:text-neutral-400 shadow-sm hover:bg-neutral-50 dark:hover:bg-neutral-800/50">Leave</button>
                        @endif
                    </div>
                @endforeach
            </div>
            @error('leave')
                <p class="mt-3 text-sm text-rose-600 dark:text-rose-400">{{ $message }}</p>
            @enderror
        </x-monitor::card>
    </x-monitor::section>
</div>
