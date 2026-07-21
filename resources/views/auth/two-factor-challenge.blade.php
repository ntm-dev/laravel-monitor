{{-- Post-password TOTP challenge. See Http\Controllers\Auth\TwoFactorChallengeController. --}}
<x-monitor::layout title="Two-factor authentication">
    <div class="flex min-h-screen items-center justify-center bg-neutral-50 px-4 dark:bg-neutral-950">
        <div class="w-full max-w-sm">
            <div class="rounded-lg border border-neutral-200 bg-white p-6 shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                <h1 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">Two-factor authentication</h1>
                <p class="mt-1 text-sm text-neutral-500 dark:text-neutral-400">Enter the 6-digit code from your authenticator app, or a recovery code.</p>

                @if ($errors->any())
                    <div class="mt-4 rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-600 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-400">
                        {{ $errors->first() }}
                    </div>
                @endif

                <form method="POST" action="{{ route('monitor.two-factor.challenge.store') }}" class="mt-4 space-y-4">
                    @csrf
                    <div>
                        <label for="code" class="block font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400">Code</label>
                        <input type="text" name="code" id="code" required autofocus
                               class="mt-1 w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-900 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100">
                    </div>
                    <button type="submit" class="w-full rounded-md bg-blue-600 py-2 text-sm font-medium text-white hover:bg-blue-500">Verify</button>
                </form>
            </div>
        </div>
    </div>
</x-monitor::layout>
