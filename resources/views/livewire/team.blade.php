@php
    use LaravelMonitor\Support\Icons;

    $actor = request()->user(\LaravelMonitor\Models\MonitorUser::guardName());
    $t = fn (string $key, array $replace = []) => __('monitor::messages.team.'.$key, $replace);
    $roleLabel = fn (string $role) => match ($role) {
        'owner' => $t('role_owner'),
        'admin' => $t('role_admin'),
        default => $t('role_viewer'),
    };
    $roleBadge = fn (string $role) => match ($role) {
        'owner' => 'border-blue-200 dark:border-blue-500/30 bg-blue-50 dark:bg-blue-500/10 text-blue-600 dark:text-blue-400',
        'admin' => 'border-amber-200 dark:border-amber-500/30 bg-amber-50 dark:bg-amber-500/10 text-amber-600 dark:text-amber-400',
        default => 'border-neutral-200 dark:border-neutral-700 bg-neutral-100/80 dark:bg-neutral-800/80 text-neutral-500 dark:text-neutral-400',
    };
    $popupCard = 'w-full max-w-sm rounded-lg border border-neutral-200 bg-white p-5 shadow-lg dark:border-neutral-800 dark:bg-neutral-900';
    $popupOverlay = 'fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4';
    $fieldClass = 'mt-1 w-full rounded-md border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 px-2 py-1.5 text-sm text-neutral-900 dark:text-neutral-100 focus:outline-none';
    $labelClass = 'block font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400';
    $secondaryButton = 'rounded-md border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 px-3 py-1.5 text-sm font-medium text-neutral-500 dark:text-neutral-400 shadow-sm hover:bg-neutral-50 dark:hover:bg-neutral-800/50';
    $primaryButton = 'rounded-md bg-blue-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-blue-500';
@endphp
<div wire:poll.{{ $refresh }}s>
    <x-monitor::section :icon="Icons::PROFILE" title="{{ __('monitor::messages.team.my_account') }}">
        <x-monitor::card class="p-4">
        <div class="divide-y divide-neutral-100 dark:divide-neutral-800">
        <div class="pb-4">
            <div x-data="{ editing: false }" x-on:name-updated.window="editing = false">
                <template x-if="! editing">
                    <div class="flex items-center gap-1.5">
                        <span class="font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400">{{ $t('name') }}</span>
                        <span class="min-w-0 flex-1 truncate text-sm text-right text-neutral-700 dark:text-neutral-200">{{ $actor->name }}</span>
                        <button type="button" x-on:click="editing = true; $wire.startEditingName()" title="{{ $t('edit_name') }}"
                                class="shrink-0 rounded p-1 text-neutral-400 hover:text-neutral-700 dark:text-neutral-500 dark:hover:text-neutral-200">
                            <x-monitor::icon :path="Icons::PENCIL" class="h-4 w-4"/>
                        </button>
                    </div>
                </template>
                <template x-if="editing">
                    <form x-on:submit.prevent="$wire.updateName($refs.name.value)" class="flex flex-wrap items-end gap-2">
                        <div class="min-w-0 flex-1">
                            <label class="{{ $labelClass }}">{{ $t('name') }}</label>
                            <input type="text" x-ref="name" required autofocus value="{{ $actor->name }}" class="{{ $fieldClass }}">
                        </div>
                        <button type="submit" class="h-8 rounded-md bg-blue-600 px-3 text-sm font-medium text-white hover:bg-blue-500">{{ $t('save') }}</button>
                        <button type="button" x-on:click="editing = false"
                                class="h-8 rounded-md border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 px-3 text-sm font-medium text-neutral-500 dark:text-neutral-400 shadow-sm hover:bg-neutral-50 dark:hover:bg-neutral-800/50">{{ $t('cancel') }}</button>
                    </form>
                </template>
            </div>
            @error('name')
                <p class="text-sm text-rose-600 dark:text-rose-400">{{ $message }}</p>
            @enderror
            @if ($nameUpdated)
                <p class="text-sm text-emerald-600 dark:text-emerald-400">{{ $t('name_updated') }}</p>
            @endif
        </div>

        <div class="py-4">
            <div class="flex items-center gap-1.5" x-data="{ open: false }" x-on:email-change-requested.window="open = false">
                <span class="font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400">{{ $t('email_address') }}</span>
                <span class="min-w-0 flex-1 truncate text-sm text-right text-neutral-700 dark:text-neutral-200">{{ $actor->email }}</span>
                <button type="button" x-on:click="open = true; $wire.startEditingEmail()" title="{{ $t('edit_email') }}"
                        class="shrink-0 rounded p-1 text-neutral-400 hover:text-neutral-700 dark:text-neutral-500 dark:hover:text-neutral-200">
                    <x-monitor::icon :path="Icons::PENCIL" class="h-4 w-4"/>
                </button>

                <div x-show="open" x-cloak x-on:keydown.escape.window="open = false" class="{{ $popupOverlay }}">
                    <div x-on:click.outside="open = false" class="{{ $popupCard }}">
                        <h3 class="text-sm font-semibold text-neutral-900 dark:text-neutral-100">{{ $t('edit_email') }}</h3>
                        <form x-on:submit.prevent="$wire.requestEmailChange($refs.newEmail.value, $refs.currentPassword.value)" class="mt-3 space-y-3">
                            <div>
                                <label class="{{ $labelClass }}">{{ $t('new_email') }}</label>
                                <input type="email" x-ref="newEmail" required autofocus class="{{ $fieldClass }}">
                            </div>
                            <div>
                                <label class="{{ $labelClass }}">{{ $t('current_password') }}</label>
                                <input type="password" x-ref="currentPassword" required class="{{ $fieldClass }}">
                            </div>
                            @error('newEmail')
                                <p class="text-sm text-rose-600 dark:text-rose-400">{{ $message }}</p>
                            @enderror
                            @error('emailPassword')
                                <p class="text-sm text-rose-600 dark:text-rose-400">{{ $message }}</p>
                            @enderror
                            <div class="flex justify-end gap-2 pt-1">
                                <button type="button" x-on:click="open = false; $wire.cancelEditingEmail()" class="{{ $secondaryButton }}">{{ $t('cancel') }}</button>
                                <button type="submit" class="{{ $primaryButton }}">{{ $t('verify') }}</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            @if ($emailChangeRequested)
                <p class="text-sm text-emerald-600 dark:text-emerald-400">{{ $t('email_change_sent') }}</p>
            @endif
        </div>

        <div class="py-4" x-data="{ open: false }" x-on:password-changed.window="open = false">
            <div class="flex items-center gap-3 justify-between">
                <span class="font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400">{{ $t('password') }}</span>
                <button type="button" x-on:click="open = true"
                        class="h-8 rounded-md text border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 px-3 text-sm font-medium text-neutral-700 dark:text-neutral-200 shadow-sm hover:bg-neutral-50 dark:hover:bg-neutral-800/50">{{ $t('change_password') }}</button>

            </div>
            @if ($passwordChanged)
                <p class="mt-2 text-sm text-emerald-600 dark:text-emerald-400">{{ $t('password_changed') }}</p>
            @endif
            <div x-show="open" x-cloak x-on:keydown.escape.window="open = false" class="{{ $popupOverlay }}">
                <div x-on:click.outside="open = false" class="{{ $popupCard }}">
                    <h3 class="text-sm font-semibold text-neutral-900 dark:text-neutral-100">{{ $t('change_password') }}</h3>
                    <form x-on:submit.prevent="$wire.changePassword($refs.currentPassword.value, $refs.newPassword.value, $refs.newPasswordConfirmation.value)" class="mt-3 space-y-3">
                        <div>
                            <label class="{{ $labelClass }}">{{ $t('current_password') }}</label>
                            <input type="password" x-ref="currentPassword" required class="{{ $fieldClass }}">
                        </div>
                        <div>
                            <label class="{{ $labelClass }}">{{ $t('new_password') }}</label>
                            <input type="password" x-ref="newPassword" required class="{{ $fieldClass }}">
                        </div>
                        <div>
                            <label class="{{ $labelClass }}">{{ $t('confirm_new_password') }}</label>
                            <input type="password" x-ref="newPasswordConfirmation" required class="{{ $fieldClass }}">
                        </div>
                        @error('currentPassword')
                            <p class="text-sm text-rose-600 dark:text-rose-400">{{ $message }}</p>
                        @enderror
                        @error('newPassword')
                            <p class="text-sm text-rose-600 dark:text-rose-400">{{ $message }}</p>
                        @enderror
                        <div class="flex justify-end gap-2 pt-1">
                            <button type="button" x-on:click="open = false" class="{{ $secondaryButton }}">{{ $t('cancel') }}</button>
                            <button type="submit" class="{{ $primaryButton }}">{{ $t('change_password') }}</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="py-4">
            <div class="flex items-center gap-3 justify-between">
                <span class="font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400">{{ $t('two_factor') }}</span>
                @if (! \LaravelMonitor\Support\OptionalAuthMethod::totpAvailable())
                    <span class="mt-2 text-sm text-neutral-400 dark:text-neutral-500">{{ $t('totp_install_hint', ['packages' => 'pragmarx/google2fa bacon/bacon-qr-code']) }}</span>
                @else
                <div class="mt-3"
                     x-data="{ open: false, totpEnabled: {{ $actor->hasTotpEnabled() ? 'true' : 'false' }} }"
                     x-on:totp-enabled.window="open = false"
                     x-on:totp-disabled.window="open = false; totpEnabled = false">
                    <x-monitor::toggle name="totp" :checked="$actor->hasTotpEnabled()" x-model="totpEnabled"
                        x-on:change="open = true; if (totpEnabled) { $wire.startEnrollingTotp(); }" />

                    <div x-show="open" x-cloak
                         x-on:keydown.escape.window="open = false; totpEnabled = {{ $actor->hasTotpEnabled() ? 'true' : 'false' }}; $wire.cancelEnrollingTotp()"
                         class="{{ $popupOverlay }}">
                        <div x-on:click.outside="open = false; totpEnabled = {{ $actor->hasTotpEnabled() ? 'true' : 'false' }}; $wire.cancelEnrollingTotp()" class="{{ $popupCard }}">
                            <template x-if="totpEnabled">
                                <div>
                                    <h3 class="text-sm font-semibold text-neutral-900 dark:text-neutral-100">{{ $t('enable_two_factor') }}</h3>
                                    @if ($totpSecret === null)
                                        <p class="mt-2 text-sm text-neutral-500 dark:text-neutral-400">{{ $t('generating_secret') }}</p>
                                    @else
                                        <div class="mt-3">
                                            {!! (new BaconQrCode\Writer(new BaconQrCode\Renderer\ImageRenderer(new BaconQrCode\Renderer\RendererStyle\RendererStyle(200), new BaconQrCode\Renderer\Image\SvgImageBackEnd())))->writeString((new PragmaRX\Google2FA\Google2FA())->getQRCodeUrl(config('app.name', 'Laravel'), $actor->email, $totpSecret)) !!}
                                            <p class="mt-2 font-mono text-xs text-neutral-500 dark:text-neutral-400">{{ $totpSecret }}</p>
                                        </div>
                                        <form x-on:submit.prevent="$wire.confirmTotp($refs.totpCode.value)" class="mt-3 space-y-3">
                                            <div>
                                                <label class="{{ $labelClass }}">{{ $t('enter_code') }}</label>
                                                <input type="text" x-ref="totpCode" required inputmode="numeric" pattern="[0-9]*" maxlength="6" class="{{ $fieldClass }}">
                                            </div>
                                            @error('totp')
                                                <p class="text-sm text-rose-600 dark:text-rose-400">{{ $message }}</p>
                                            @enderror
                                            <div class="flex justify-end gap-2 pt-1">
                                                <button type="button"
                                                        x-on:click="open = false; totpEnabled = {{ $actor->hasTotpEnabled() ? 'true' : 'false' }}; $wire.cancelEnrollingTotp()"
                                                        class="{{ $secondaryButton }}">{{ $t('cancel') }}</button>
                                                <button type="submit" class="{{ $primaryButton }}">{{ $t('confirm') }}</button>
                                            </div>
                                        </form>
                                    @endif
                                </div>
                            </template>
                            <template x-if="! totpEnabled">
                                <div>
                                    <h3 class="text-sm font-semibold text-neutral-900 dark:text-neutral-100">{{ $t('disable_two_factor') }}</h3>
                                    <p class="mt-2 text-sm text-neutral-500 dark:text-neutral-400">{{ $t('enter_password_to_confirm') }}</p>
                                    <form x-on:submit.prevent="$wire.disableTotp($refs.currentPassword.value)" class="mt-3 space-y-3">
                                        <div>
                                            <label class="{{ $labelClass }}">{{ $t('current_password') }}</label>
                                            <input type="password" x-ref="currentPassword" required class="{{ $fieldClass }}">
                                        </div>
                                        @error('totp')
                                            <p class="text-sm text-rose-600 dark:text-rose-400">{{ $message }}</p>
                                        @enderror
                                        <div class="flex justify-end gap-2 pt-1">
                                            <button type="button" x-on:click="open = false; totpEnabled = true" class="{{ $secondaryButton }}">{{ $t('cancel') }}</button>
                                            <button type="submit" class="rounded-md bg-rose-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-rose-500">{{ $t('disable') }}</button>
                                        </div>
                                    </form>
                                </div>
                            </template>
                        </div>
                    </div>
                </div>
            </div>
            @endif
        </div>

        <div class="pt-4">
            <div class="flex items-center gap-3 justify-between">
                <span class="font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400">{{ $t('passkeys') }}</span>
                @if (! \LaravelMonitor\Support\OptionalAuthMethod::passkeysAvailable())
                    <span class="mt-2 text-sm text-neutral-400 dark:text-neutral-500">{{ $t('passkeys_install_hint', ['package' => 'web-auth/webauthn-lib']) }}</span>
                @else
                    <div class="mt-3 divide-y divide-neutral-100 dark:divide-neutral-800">
                        @foreach ($passkeys as $passkey)
                            <div class="flex items-center gap-3 py-2">
                                <span class="min-w-0 flex-1 truncate text-sm text-neutral-700 dark:text-neutral-200">{{ $passkey->label }}</span>
                                <button type="button" wire:click="removePasskey({{ $passkey->id }})" wire:confirm="{{ $t('remove_passkey_confirm') }}"
                                        class="shrink-0 rounded-md border border-rose-200 dark:border-rose-500/30 bg-white dark:bg-neutral-900 px-2 py-1 font-mono text-[10px] uppercase tracking-tight text-rose-600 dark:text-rose-400 shadow-sm hover:bg-rose-50 dark:hover:bg-rose-500/10">{{ $t('remove') }}</button>
                            </div>
                        @endforeach
                    </div>

                    <div x-data="{ open: false }">
                        <button type="button" x-on:click="open = true"
                                class="mt-3 inline-flex h-8 items-center gap-1.5 rounded-md border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 px-3 text-sm font-medium text-neutral-700 dark:text-neutral-200 shadow-sm hover:bg-neutral-50 dark:hover:bg-neutral-800/50">
                            <x-monitor::icon :path="Icons::KEY" class="h-4 w-4"/>
                            {{ $t('add_passkey') }}
                        </button>

                        <div x-show="open" x-cloak x-on:keydown.escape.window="open = false" class="{{ $popupOverlay }}">
                            <div x-on:click.outside="open = false" class="{{ $popupCard }}">
                                <h3 class="text-sm font-semibold text-neutral-900 dark:text-neutral-100">{{ $t('add_passkey') }}</h3>
                                <form x-on:submit.prevent="addPasskey($refs.label.value); open = false" class="mt-3 space-y-3">
                                    <div>
                                        <label class="{{ $labelClass }}">{{ $t('label') }}</label>
                                        <input type="text" x-ref="label" required value="My device" class="{{ $fieldClass }}">
                                    </div>
                                    <div class="flex justify-end gap-2 pt-1">
                                        <button type="button" x-on:click="open = false" class="{{ $secondaryButton }}">{{ $t('cancel') }}</button>
                                        <button type="submit" class="{{ $primaryButton }}">{{ $t('continue') }}</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                    <script>
                        // The server's JSON (registerOptions()) and the browser's WebAuthn API disagree
                        // on wire format: the API wants ArrayBuffers for challenge/user.id/credential ids,
                        // while the JSON we fetch (and the JSON we POST back) carries base64url strings.
                        // These two helpers bridge that gap in both directions.
                        function base64UrlToArrayBuffer(base64Url) {
                            const base64 = base64Url.replace(/-/g, '+').replace(/_/g, '/');
                            const binary = atob(base64);
                            const bytes = new Uint8Array(binary.length);
                            for (let i = 0; i < binary.length; i++) {
                                bytes[i] = binary.charCodeAt(i);
                            }
                            return bytes.buffer;
                        }

                        function arrayBufferToBase64Url(buffer) {
                            const bytes = new Uint8Array(buffer);
                            let binary = '';
                            for (let i = 0; i < bytes.length; i++) {
                                binary += String.fromCharCode(bytes[i]);
                            }
                            return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
                        }

                        async function addPasskey(label) {
                            const options = await (await fetch('{{ route('monitor.webauthn.register.options') }}', {
                                method: 'POST',
                                headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                            })).json();

                            options.challenge = base64UrlToArrayBuffer(options.challenge);
                            options.user.id = base64UrlToArrayBuffer(options.user.id);
                            (options.excludeCredentials ?? []).forEach((credential) => {
                                credential.id = base64UrlToArrayBuffer(credential.id);
                            });

                            const credential = await navigator.credentials.create({ publicKey: options });

                            await fetch('{{ route('monitor.webauthn.register.store') }}', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                                body: JSON.stringify({
                                    label: label || 'Passkey',
                                    response: {
                                        id: credential.id,
                                        rawId: arrayBufferToBase64Url(credential.rawId),
                                        type: credential.type,
                                        response: {
                                            clientDataJSON: arrayBufferToBase64Url(credential.response.clientDataJSON),
                                            attestationObject: arrayBufferToBase64Url(credential.response.attestationObject),
                                        },
                                    },
                                }),
                            });

                            window.location.reload();
                        }
                    </script>
                @endif
            </div>
        </div>
        </div>
        </x-monitor::card>

        <div x-data="{ codes: null }" x-on:totp-enabled.window="codes = $event.detail.recoveryCodes" x-show="codes" x-cloak>
            <x-monitor::card class="mt-4 p-4">
                <p class="font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400">{{ $t('recovery_codes_title') }}</p>
                <ul class="mt-3 grid grid-cols-2 gap-1 font-mono text-sm text-neutral-700 dark:text-neutral-200">
                    <template x-for="code in codes" :key="code">
                        <li x-text="code"></li>
                    </template>
                </ul>
            </x-monitor::card>
        </div>

        @if ($pendingInvitations->isNotEmpty())
            <div class="mt-6 flex items-center gap-2 px-1 pb-3">
                <h3 class="font-semibold text-neutral-900 dark:text-neutral-100">{{ number_format($pendingInvitations->count()) }} {{ $pendingInvitations->count() === 1 ? $t('pending_invite') : $t('pending_invites') }}</h3>
            </div>
            <x-monitor::card class="p-4">
                <div class="divide-y divide-neutral-100 dark:divide-neutral-800">
                    @foreach ($pendingInvitations as $invitation)
                        <div class="flex items-center gap-3 py-2.5">
                            <span class="min-w-0 flex-1 truncate font-mono text-sm text-neutral-700 dark:text-neutral-200">{{ $invitation->email }}</span>
                            <span class="shrink-0 rounded border px-1.5 py-0.5 font-mono text-[10px] uppercase tracking-tight {{ $roleBadge($invitation->role) }}">{{ $roleLabel($invitation->role) }}</span>
                            <span class="shrink-0 font-mono text-xs text-neutral-400 dark:text-neutral-500">{{ $t('expires', ['time' => $invitation->expires_at->diffForHumans()]) }}</span>
                            @if ($actor->isOwner() || $invitation->invited_by === $actor->id)
                                <button type="button" wire:click="cancelInvite({{ $invitation->id }})"
                                        class="shrink-0 rounded-md border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 px-2 py-1 font-mono text-[10px] uppercase tracking-tight text-neutral-500 dark:text-neutral-400 shadow-sm hover:bg-neutral-50 dark:hover:bg-neutral-800/50">{{ $t('cancel') }}</button>
                            @endif
                        </div>
                    @endforeach
                </div>
            </x-monitor::card>
        @endif

        @if ($pendingEmailChanges->isNotEmpty())
            <div class="mt-6 flex items-center gap-2 px-1 pb-3">
                <h3 class="font-semibold text-neutral-900 dark:text-neutral-100">{{ number_format($pendingEmailChanges->count()) }} {{ $pendingEmailChanges->count() === 1 ? $t('pending_email_change') : $t('pending_email_changes') }}</h3>
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
                                        class="shrink-0 rounded-md border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 px-2 py-1 font-mono text-[10px] uppercase tracking-tight text-neutral-500 dark:text-neutral-400 shadow-sm hover:bg-neutral-50 dark:hover:bg-neutral-800/50">{{ $t('approve') }}</button>
                                <button type="button" wire:click="rejectEmailChange({{ $emailChange->id }})" wire:confirm="{{ $t('reject_email_change_confirm') }}"
                                        class="shrink-0 rounded-md border border-rose-200 dark:border-rose-500/30 bg-white dark:bg-neutral-900 px-2 py-1 font-mono text-[10px] uppercase tracking-tight text-rose-600 dark:text-rose-400 shadow-sm hover:bg-rose-50 dark:hover:bg-rose-500/10">{{ $t('reject') }}</button>
                            @endif
                        </div>
                    @endforeach
                </div>
                @error('emailChange')
                    <p class="mt-3 text-sm text-rose-600 dark:text-rose-400">{{ $message }}</p>
                @enderror
            </x-monitor::card>
        @endif

        <div class="mt-6 flex items-center justify-between gap-2 px-1 pb-3">
            <h3 class="flex items-center gap-1.5 font-semibold text-neutral-900 dark:text-neutral-100">
                <x-monitor::icon :path="Icons::USER_GROUP" class="h-4 w-4 text-neutral-400 dark:text-neutral-500"/>
                {{ number_format($members->count()) }} {{ $members->count() === 1 ? $t('member') : $t('members') }}
            </h3>
            <div class="flex flex-wrap items-center gap-2">
                <input type="search" wire:model.live.debounce.300ms="memberSearch" placeholder="{{ $t('search_members') }}"
                       class="min-w-0 flex-1 rounded-md border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 px-2 py-1.5 text-sm text-neutral-900 dark:text-neutral-100 focus:outline-none">
                <select wire:model.live="memberRoleFilter"
                        class="rounded-md border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 px-2 py-1.5 text-sm text-neutral-900 dark:text-neutral-100 focus:outline-none">
                    <option value="all">{{ $t('all_roles') }}</option>
                    <option value="owner">{{ $t('role_owner') }}</option>
                    <option value="admin">{{ $t('role_admin') }}</option>
                    <option value="viewer">{{ $t('role_viewer') }}</option>
                </select>
            @if ($actor->canManageTeam())
                <div x-data="{ open: false }" x-on:member-invited.window="open = false" >
                    <button type="button" x-on:click="open = true"
                            class="inline-flex h-8 items-center gap-1.5 rounded-md border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 px-3 text-sm font-medium text-neutral-700 dark:text-neutral-200 shadow-sm hover:bg-neutral-50 dark:hover:bg-neutral-800/50">
                        <x-monitor::icon :path="Icons::TEAM" :view-box="'0 0 24 24'" :fill="'none'" :transform="null" class="h-4 w-4"/>
                        {{ $t('invite') }}
                    </button>

                    <div x-show="open" x-cloak x-on:keydown.escape.window="open = false" class="{{ $popupOverlay }}">
                        <div x-on:click.outside="open = false" class="{{ $popupCard }}">
                            <h3 class="text-sm font-semibold text-neutral-900 dark:text-neutral-100">{{ $t('invite_a_member') }}</h3>
                            <form x-on:submit.prevent="$wire.invite($refs.email.value, $refs.role.value)" class="mt-3 space-y-3">
                                <div>
                                    <label class="{{ $labelClass }}">{{ $t('email') }}</label>
                                    <input type="email" x-ref="email" required class="{{ $fieldClass }}">
                                </div>
                                <div>
                                    <label class="{{ $labelClass }}">{{ $t('role') }}</label>
                                    <select x-ref="role" class="{{ $fieldClass }}">
                                        <option value="viewer">{{ $t('role_viewer') }}</option>
                                        <option value="admin">{{ $t('role_admin') }}</option>
                                    </select>
                                </div>
                                @error('email')
                                    <p class="text-sm text-rose-600 dark:text-rose-400">{{ $message }}</p>
                                @enderror
                                <div class="flex justify-end gap-2 pt-1">
                                    <button type="button" x-on:click="open = false" class="{{ $secondaryButton }}">{{ $t('cancel') }}</button>
                                    <button type="submit" class="{{ $primaryButton }}">{{ $t('send_invite') }}</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            @endif
            </div>
        </div>

        <x-monitor::card class="p-4">
            <div class="divide-y divide-neutral-100 dark:divide-neutral-800">
                @forelse ($members as $member)
                    <div class="flex items-center gap-3 py-2.5">
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium text-neutral-900 dark:text-neutral-100">
                                {{ $member->name }}
                                @if ($member->id === $actor->id)
                                    <span class="ml-1 text-xs font-normal text-neutral-400 dark:text-neutral-500">({{ $t('you') }})</span>
                                @endif
                            </p>
                            <p class="truncate font-mono text-xs text-neutral-400 dark:text-neutral-500">{{ $member->email }}</p>
                        </div>
                        @if ($actor->isOwner() && $member->id !== $actor->id)
                            @php
                                $changeRoleMessages = [
                                    'admin' => $t('change_role_confirm', ['name' => $member->name, 'role' => $t('role_admin')]),
                                    'viewer' => $t('change_role_confirm', ['name' => $member->name, 'role' => $t('role_viewer')]),
                                ];
                            @endphp
                            <div x-data="{ role: '{{ $member->role }}' }">
                                <select
                                    x-model="role"
                                    x-on:change="
                                        let newRole = $event.target.value;
                                        $dispatch('open-confirm-modal', {
                                            title: @js($t('change_role')),
                                            message: newRole === 'admin' ? @js($changeRoleMessages['admin']) : @js($changeRoleMessages['viewer']),
                                            confirmLabel: @js($t('change_role')),
                                            action: () => $wire.changeRole({{ $member->id }}, newRole),
                                            onCancel: () => { role = '{{ $member->role }}' },
                                        })"
                                    class="shrink-0 rounded-md border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 px-1.5 py-1 font-mono text-[10px] uppercase tracking-tight text-neutral-500 dark:text-neutral-400">
                                    <option value="admin" @selected($member->role === 'admin')>{{ $t('role_admin') }}</option>
                                    <option value="viewer" @selected($member->role === 'viewer')>{{ $t('role_viewer') }}</option>
                                </select>
                            </div>
                            <button type="button"
                                    x-on:click="$dispatch('open-confirm-modal', {
                                        title: @js($t('transfer_ownership')),
                                        message: @js($t('make_owner_confirm', ['name' => $member->name])),
                                        confirmLabel: @js($t('make_owner')),
                                        action: () => $wire.transferOwnership({{ $member->id }}),
                                    })"
                                    class="shrink-0 rounded-md border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 px-2 py-1 font-mono text-[10px] uppercase tracking-tight text-neutral-500 dark:text-neutral-400 shadow-sm hover:bg-neutral-50 dark:hover:bg-neutral-800/50">{{ $t('make_owner') }}</button>
                            @if ($member->hasTotpEnabled())
                                <button type="button" wire:click="disableMemberTotp({{ $member->id }})" wire:confirm="{{ $t('disable_2fa_confirm', ['name' => $member->name]) }}"
                                        class="shrink-0 rounded-md border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 px-2 py-1 font-mono text-[10px] uppercase tracking-tight text-neutral-500 dark:text-neutral-400 shadow-sm hover:bg-neutral-50 dark:hover:bg-neutral-800/50">{{ $t('disable_2fa') }}</button>
                            @endif
                            <button type="button"
                                    x-on:click="$dispatch('open-confirm-modal', {
                                        title: @js($t('remove_member')),
                                        message: @js($t('remove_member_confirm', ['name' => $member->name])),
                                        confirmLabel: @js($t('remove')),
                                        danger: true,
                                        action: () => $wire.removeMember({{ $member->id }}),
                                    })"
                                    class="shrink-0 rounded-md border border-rose-200 dark:border-rose-500/30 bg-white dark:bg-neutral-900 px-2 py-1 font-mono text-[10px] uppercase tracking-tight text-rose-600 dark:text-rose-400 shadow-sm hover:bg-rose-50 dark:hover:bg-rose-500/10">{{ $t('remove') }}</button>
                        @else
                            <span class="shrink-0 rounded border px-1.5 py-0.5 font-mono text-[10px] uppercase tracking-tight {{ $roleBadge($member->role) }}">{{ $roleLabel($member->role) }}</span>
                        @endif
                        @if ($member->id === $actor->id)
                            <button type="button" wire:click="leave" @unless ($soleOwner) wire:confirm="{{ $t('leave_confirm') }}" @endunless
                                    class="shrink-0 rounded-md border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 px-2 py-1 font-mono text-[10px] uppercase tracking-tight text-neutral-500 dark:text-neutral-400 shadow-sm {{ $soleOwner ? 'cursor-not-allowed opacity-50' : 'hover:bg-neutral-50 dark:hover:bg-neutral-800/50' }}">{{ $t('leave') }}</button>
                        @endif
                    </div>
                    @if ($member->id === $actor->id)
                        @error('leave')
                            <p class="pb-2.5 text-sm text-rose-600 dark:text-rose-400">{{ $message }}</p>
                        @enderror
                    @endif
                @empty
                    <p class="py-3 text-sm text-neutral-400 dark:text-neutral-500">{{ $t('no_members_match') }}</p>
                @endforelse
            </div>
        </x-monitor::card>

        <div x-data="{
                open: false,
                title: '',
                message: '',
                confirmLabel: {{ Illuminate\Support\Js::from($t('confirm')) }},
                danger: false,
                action: null,
                onCancel: null,
                confirm() { if (this.action) this.action(); this.open = false; },
                cancel() { if (this.onCancel) this.onCancel(); this.open = false; },
            }"
            x-on:open-confirm-modal.window="
                open = true;
                title = $event.detail.title;
                message = $event.detail.message;
                confirmLabel = $event.detail.confirmLabel ?? {{ Illuminate\Support\Js::from($t('confirm')) }};
                danger = $event.detail.danger ?? false;
                action = $event.detail.action;
                onCancel = $event.detail.onCancel ?? null;
            "
            x-show="open" x-cloak
            class="{{ $popupOverlay }}"
            x-on:keydown.escape.window="cancel()">
            <div x-on:click.outside="cancel()" class="{{ $popupCard }}">
                <h3 class="text-sm font-semibold text-neutral-900 dark:text-neutral-100" x-text="title"></h3>
                <p class="mt-2 text-sm text-neutral-500 dark:text-neutral-400" x-text="message"></p>
                <div class="mt-4 flex justify-end gap-2">
                    <button type="button" x-on:click="cancel()" class="{{ $secondaryButton }}">{{ $t('cancel') }}</button>
                    <button type="button" x-on:click="confirm()" x-text="confirmLabel"
                            :class="danger ? 'bg-rose-600 hover:bg-rose-500' : 'bg-blue-600 hover:bg-blue-500'"
                            class="rounded-md px-3 py-1.5 text-sm font-medium text-white"></button>
                </div>
            </div>
        </div>
    </x-monitor::section>
</div>
