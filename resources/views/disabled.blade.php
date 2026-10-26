{{-- Shown once, right after the master switch is turned off from Settings. See SettingsController::system(). --}}
<x-monitor::layout :title="__('monitor::messages.settings.disabled_page_title')">
    <div class="flex min-h-screen items-center justify-center bg-neutral-50 px-4 dark:bg-neutral-950">
        <div class="w-full max-w-md">
            <div class="mb-6 flex items-center justify-center gap-2.5">
                <span class="flex h-9 w-9 items-center justify-center rounded-md bg-neutral-900 text-sm font-semibold text-white dark:bg-neutral-700">{{ strtoupper(mb_substr(config('app.name', 'L'), 0, 1)) }}</span>
                <span class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">{{ config('app.name', 'Laravel') }} Monitor</span>
            </div>

            <div class="rounded-lg border border-neutral-200 bg-white p-6 shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                <h1 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">{{ __('monitor::messages.settings.disabled_page_title') }}</h1>
                <p class="mt-1 text-sm text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.settings.disabled_page_body') }}</p>

                <x-monitor::reenable-steps :file="$settingsFile" class="mt-5 border-t border-neutral-100 pt-4 dark:border-neutral-800" />
            </div>
        </div>
    </div>
</x-monitor::layout>
