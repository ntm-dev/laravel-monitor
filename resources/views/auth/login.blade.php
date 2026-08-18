{{-- Sign-in page. See Http\Controllers\Auth\LoginController. --}}
<x-monitor::layout :title="__('monitor::messages.auth.sign_in')">
    <div class="flex min-h-screen items-center justify-center bg-neutral-200 px-4 dark:bg-neutral-800">
        <div class="w-full max-w-sm">
            <div class="mb-6 flex items-center justify-center gap-2.5">
                <span class="flex h-9 w-9 items-center justify-center rounded-lg bg-neutral-900 text-sm font-semibold text-white dark:bg-neutral-700">{{ strtoupper(mb_substr(config('app.name', 'L'), 0, 1)) }}</span>
                <span class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">{{ config('app.name', 'Laravel') }} Monitor</span>
            </div>

            <div class="rounded-2xl bg-neutral-200 shadow-neu dark:bg-neutral-800 dark:shadow-neu-dark p-6">
                <h1 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">{{ __('monitor::messages.auth.sign_in') }}</h1>
                <p class="mt-1 text-sm text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.auth.sign_in_hint') }}</p>

                @if ($errors->any())
                    <x-monitor::alert color="rose" class="mt-4">
                        {{ $errors->first() }}
                    </x-monitor::alert>
                @endif

                <form method="POST" action="{{ route('monitor.login.store') }}" class="mt-4 space-y-4">
                    @csrf
                    <div>
                        <label for="email" class="block font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.auth.email') }}</label>
                        <input type="email" name="email" id="email" value="{{ old('email') }}" required autofocus
                               class="mt-1 w-full rounded-xl bg-neutral-200 px-3 py-2 text-sm text-neutral-800 shadow-neu-inset dark:bg-neutral-800 dark:text-neutral-100 dark:shadow-neu-dark-inset">
                    </div>
                    <div>
                        <label for="password" class="block font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.auth.password') }}</label>
                        <input type="password" name="password" id="password" required
                               class="mt-1 w-full rounded-xl bg-neutral-200 px-3 py-2 text-sm text-neutral-800 shadow-neu-inset dark:bg-neutral-800 dark:text-neutral-100 dark:shadow-neu-dark-inset">
                    </div>
                    <button type="submit" class="w-full rounded-xl bg-blue-600 dark:bg-purple-600 py-2 text-sm font-medium text-white shadow-neu-sm hover:bg-blue-500 dark:hover:bg-purple-500 active:scale-[0.98]">{{ __('monitor::messages.auth.sign_in') }}</button>
                </form>

                @if (\LaravelMonitor\Support\OptionalAuthMethod::passkeysAvailable() && \LaravelMonitor\Support\OptionalAuthMethod::webauthnDomainValid())
                    <button type="button" id="passkey-login-button"
                            class="mt-3 w-full rounded-xl bg-neutral-200 py-2 text-sm font-medium text-neutral-700 shadow-neu-sm hover:shadow-neu active:shadow-neu-inset dark:bg-neutral-800 dark:text-neutral-200 dark:shadow-neu-dark-sm dark:hover:shadow-neu-dark dark:active:shadow-neu-dark-inset">
                        Sign in with a passkey
                    </button>
                @endif
                <script>
                    // Same base64url <-> ArrayBuffer bridge as Team's "Add a passkey" script
                    // (resources/views/livewire/team.blade.php) — the server's JSON and the
                    // browser's WebAuthn API disagree on wire format.
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

                    document.getElementById('passkey-login-button')?.addEventListener('click', async () => {
                        const options = await (await fetch('{{ route('monitor.webauthn.authenticate.options') }}', {
                            method: 'POST',
                            headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                        })).json();

                        options.challenge = base64UrlToArrayBuffer(options.challenge);
                        (options.allowCredentials ?? []).forEach((credential) => {
                            credential.id = base64UrlToArrayBuffer(credential.id);
                        });

                        // Usernameless: no allowCredentials list, so the browser prompts for
                        // any discoverable passkey registered for this origin.
                        const credential = await navigator.credentials.get({ publicKey: options });

                        const response = await fetch('{{ route('monitor.webauthn.authenticate.store') }}', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                            body: JSON.stringify({
                                response: {
                                    id: credential.id,
                                    rawId: arrayBufferToBase64Url(credential.rawId),
                                    type: credential.type,
                                    response: {
                                        clientDataJSON: arrayBufferToBase64Url(credential.response.clientDataJSON),
                                        authenticatorData: arrayBufferToBase64Url(credential.response.authenticatorData),
                                        signature: arrayBufferToBase64Url(credential.response.signature),
                                        userHandle: credential.response.userHandle ? arrayBufferToBase64Url(credential.response.userHandle) : null,
                                    },
                                },
                            }),
                        });

                        window.location.href = response.url;
                    });
                </script>

                @if (\LaravelMonitor\Support\OptionalAuthMethod::oauthAvailable('google'))
                    <a href="{{ route('monitor.oauth.redirect', 'google') }}"
                       class="mt-3 flex w-full items-center justify-center rounded-xl bg-neutral-200 py-2 text-sm font-medium text-neutral-700 shadow-neu-sm hover:shadow-neu active:shadow-neu-inset dark:bg-neutral-800 dark:text-neutral-200 dark:shadow-neu-dark-sm dark:hover:shadow-neu-dark dark:active:shadow-neu-dark-inset">
                        Continue with Google
                    </a>
                @endif

                @if (\LaravelMonitor\Support\OptionalAuthMethod::oauthAvailable('apple'))
                    <a href="{{ route('monitor.oauth.redirect', 'apple') }}"
                       class="mt-3 flex w-full items-center justify-center rounded-xl bg-neutral-200 py-2 text-sm font-medium text-neutral-700 shadow-neu-sm hover:shadow-neu active:shadow-neu-inset dark:bg-neutral-800 dark:text-neutral-200 dark:shadow-neu-dark-sm dark:hover:shadow-neu-dark dark:active:shadow-neu-dark-inset">
                        Continue with Apple
                    </a>
                @endif

                <p class="mt-3 text-center text-sm text-neutral-500 dark:text-neutral-400">
                    <a href="{{ route('monitor.password.request') }}" class="text-blue-600 hover:underline dark:text-purple-400">Forgot your password?</a>
                </p>
            </div>
        </div>
    </div>
</x-monitor::layout>
