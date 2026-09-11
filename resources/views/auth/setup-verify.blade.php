{{-- First-run flow, step 1: confirm shell access before the owner-account
     form unlocks. See Http\Controllers\Auth\SetupController. --}}
<x-monitor::layout :title="__('monitor::messages.auth.set_up')">
    <div class="flex min-h-screen items-center justify-center bg-neutral-50 px-4 dark:bg-neutral-950">
        <div class="w-full max-w-sm">
            <div class="mb-6 flex items-center justify-center gap-2.5">
                <span class="flex h-9 w-9 items-center justify-center rounded-md bg-neutral-900 text-sm font-semibold text-white dark:bg-neutral-700">{{ strtoupper(mb_substr(config('app.name', 'L'), 0, 1)) }}</span>
                <span class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">{{ config('app.name', 'Laravel') }} Monitor</span>
            </div>

            <div class="rounded-lg border border-neutral-200 bg-white p-6 shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                <h1 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">{{ __('monitor::messages.auth.verify_server_access') }}</h1>
                <p class="mt-1 text-sm text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.auth.verify_server_access_hint') }}</p>

                {{-- start setup code command hint --}}
                <p class="mt-4 rounded-md border border-neutral-200 bg-neutral-50 px-3 py-2 font-mono text-xs text-neutral-600 dark:border-neutral-700 dark:bg-neutral-800/60 dark:text-neutral-300">
                    php artisan monitor:setup-code
                </p>
                {{-- end setup code command hint --}}

                @if ($errors->any())
                    <div class="mt-4 rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-600 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-400">
                        {{ $errors->first() }}
                    </div>
                @endif

                <form method="POST" action="{{ route('monitor.setup.verify-code') }}" class="mt-4 space-y-4">
                    @csrf
                    <div>
                        <label for="code" class="block font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.auth.setup_code') }}</label>
                        <input type="text" name="code" id="code" value="{{ old('code') }}" required autofocus autocomplete="off"
                               class="mt-1 w-full rounded-md border border-neutral-200 bg-white px-3 py-2 font-mono text-sm text-neutral-900 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100">
                    </div>
                    <button type="submit" class="w-full rounded-md bg-blue-600 py-2 text-sm font-medium text-white hover:bg-blue-500">{{ __('monitor::messages.auth.verify_code_button') }}</button>
                </form>
            </div>
        </div>
    </div>
</x-monitor::layout>
