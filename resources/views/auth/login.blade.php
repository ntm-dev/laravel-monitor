{{-- Sign-in page. See Http\Controllers\Auth\LoginController. --}}
<x-monitor::layout title="Sign in">
    <div class="flex min-h-screen items-center justify-center bg-neutral-50 px-4 dark:bg-neutral-950">
        <div class="w-full max-w-sm">
            <div class="mb-6 flex items-center justify-center gap-2.5">
                <span class="flex h-9 w-9 items-center justify-center rounded-md bg-neutral-900 text-sm font-semibold text-white dark:bg-neutral-700">{{ strtoupper(mb_substr(config('app.name', 'L'), 0, 1)) }}</span>
                <span class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">{{ config('app.name', 'Laravel') }} Monitor</span>
            </div>

            <div class="rounded-lg border border-neutral-200 bg-white p-6 shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                <h1 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">Sign in</h1>
                <p class="mt-1 text-sm text-neutral-500 dark:text-neutral-400">Sign in to view the monitoring dashboard.</p>

                @if ($errors->any())
                    <div class="mt-4 rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-600 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-400">
                        {{ $errors->first() }}
                    </div>
                @endif

                <form method="POST" action="{{ route('monitor.login.store') }}" class="mt-4 space-y-4">
                    @csrf
                    <div>
                        <label for="email" class="block font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400">Email</label>
                        <input type="email" name="email" id="email" value="{{ old('email') }}" required autofocus
                               class="mt-1 w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-900 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100">
                    </div>
                    <div>
                        <label for="password" class="block font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400">Password</label>
                        <input type="password" name="password" id="password" required
                               class="mt-1 w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-900 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100">
                    </div>
                    <button type="submit" class="w-full rounded-md bg-blue-600 py-2 text-sm font-medium text-white hover:bg-blue-500">Sign in</button>
                </form>

                <button type="button" id="passkey-login-button" @if (! \LaravelMonitor\Support\OptionalAuthMethod::passkeysAvailable()) disabled @endif
                        class="mt-3 w-full rounded-md border border-neutral-200 py-2 text-sm font-medium text-neutral-700 hover:bg-neutral-50 disabled:cursor-not-allowed disabled:opacity-50 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800/50">
                    Sign in with a passkey
                </button>
                @unless (\LaravelMonitor\Support\OptionalAuthMethod::passkeysAvailable())
                    <p class="mt-1 text-center text-xs text-neutral-400 dark:text-neutral-500">Install <code class="font-mono">web-auth/webauthn-lib</code> to enable this.</p>
                @endunless
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

                <a href="{{ \LaravelMonitor\Support\OptionalAuthMethod::oauthAvailable('google') ? route('monitor.oauth.redirect', 'google') : '#' }}"
                   class="mt-3 flex w-full items-center justify-center rounded-md border border-neutral-200 py-2 text-sm font-medium text-neutral-700 hover:bg-neutral-50 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800/50 {{ \LaravelMonitor\Support\OptionalAuthMethod::oauthAvailable('google') ? '' : 'cursor-not-allowed opacity-50' }}">
                    Continue with Google
                </a>
                @unless (\LaravelMonitor\Support\OptionalAuthMethod::oauthAvailable('google'))
                    <p class="mt-1 text-center text-xs text-neutral-400 dark:text-neutral-500">Install <code class="font-mono">laravel/socialite</code> and configure <code class="font-mono">MONITOR_GOOGLE_CLIENT_ID</code> to enable this.</p>
                @endunless

                <p class="mt-3 text-center text-sm text-neutral-500 dark:text-neutral-400">
                    <a href="{{ route('monitor.password.request') }}" class="text-blue-600 hover:underline dark:text-blue-400">Forgot your password?</a>
                </p>
            </div>
        </div>
    </div>
</x-monitor::layout>
