{{-- Email-change verification result. See Http\Controllers\Auth\EmailChangeController. --}}
<x-monitor::layout title="Email verified">
    <div class="flex min-h-screen items-center justify-center bg-neutral-50 px-4 dark:bg-neutral-950">
        <div class="w-full max-w-sm">
            <div class="mb-6 flex items-center justify-center gap-2.5">
                <span class="flex h-9 w-9 items-center justify-center rounded-md bg-neutral-900 text-sm font-semibold text-white dark:bg-neutral-700">{{ strtoupper(mb_substr(config('app.name', 'L'), 0, 1)) }}</span>
                <span class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">{{ config('app.name', 'Laravel') }} Monitor</span>
            </div>

            <div class="rounded-lg border border-neutral-200 bg-white p-6 shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                @if ($applied)
                    <h1 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">Email updated</h1>
                    <p class="mt-1 text-sm text-neutral-500 dark:text-neutral-400">Your account email is now <strong>{{ $newEmail }}</strong>.</p>
                @else
                    <h1 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">Email verified</h1>
                    <p class="mt-1 text-sm text-neutral-500 dark:text-neutral-400">Thanks — <strong>{{ $newEmail }}</strong> is verified. An owner or admin needs to approve the change before it takes effect.</p>
                @endif
            </div>
        </div>
    </div>
</x-monitor::layout>
