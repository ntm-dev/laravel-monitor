{{-- Post-password TOTP challenge. See Http\Controllers\Auth\TwoFactorChallengeController. --}}
<x-monitor::layout :title="__('monitor::messages.auth.two_factor_authentication')">
    <div class="flex min-h-screen items-center justify-center bg-neutral-200 px-4 dark:bg-neutral-800">
        <div class="w-full max-w-sm">
            <div class="rounded-2xl bg-neutral-200 shadow-neu dark:bg-neutral-800 dark:shadow-neu-dark p-6">
                <h1 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">{{ __('monitor::messages.auth.two_factor_authentication') }}</h1>
                <p class="mt-1 text-sm text-neutral-500 dark:text-neutral-400">Enter the 6-digit code from your authenticator app, or a recovery code.</p>

                @if ($errors->any())
                    <x-monitor::alert color="rose" class="mt-4">
                        {{ $errors->first() }}
                    </x-monitor::alert>
                @endif

                <form method="POST" action="{{ route('monitor.two-factor.challenge.store') }}" class="mt-4 space-y-4">
                    @csrf
                    <div>
                        <label for="code" class="block font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.auth.code') }}</label>
                        <input type="text" name="code" id="code" required autofocus
                               class="mt-1 w-full rounded-xl bg-neutral-200 px-3 py-2 text-sm text-neutral-800 shadow-neu-inset dark:bg-neutral-800 dark:text-neutral-100 dark:shadow-neu-dark-inset">
                    </div>
                    <button type="submit" class="w-full rounded-xl bg-blue-600 dark:bg-purple-600 py-2 text-sm font-medium text-white shadow-neu-sm hover:bg-blue-500 dark:hover:bg-purple-500 active:scale-[0.98]">{{ __('monitor::messages.auth.verify') }}</button>
                </form>
            </div>
        </div>
    </div>
</x-monitor::layout>
