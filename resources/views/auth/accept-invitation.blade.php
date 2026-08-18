{{-- Invite-acceptance page. See Http\Controllers\Auth\InvitationController. --}}
<x-monitor::layout :title="__('monitor::messages.auth.accept_invitation')">
    <div class="flex min-h-screen items-center justify-center bg-neutral-200 px-4 dark:bg-neutral-800">
        <div class="w-full max-w-sm">
            <div class="mb-6 flex items-center justify-center gap-2.5">
                <span class="flex h-9 w-9 items-center justify-center rounded-lg bg-neutral-900 text-sm font-semibold text-white dark:bg-neutral-700">{{ strtoupper(mb_substr(config('app.name', 'L'), 0, 1)) }}</span>
                <span class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">{{ config('app.name', 'Laravel') }} Monitor</span>
            </div>

            <div class="rounded-2xl bg-neutral-200 shadow-neu dark:bg-neutral-800 dark:shadow-neu-dark p-6">
                @if ($expired)
                    <h1 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">{{ __('monitor::messages.auth.invitation_expired') }}</h1>
                    <p class="mt-1 text-sm text-neutral-500 dark:text-neutral-400">Ask an owner or admin to invite {{ $invitation->email }} again.</p>
                @else
                    <h1 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">Join {{ config('app.name', 'Laravel') }} Monitor</h1>
                    <p class="mt-1 text-sm text-neutral-500 dark:text-neutral-400">Create your account for <strong>{{ $invitation->email }}</strong> as {{ $invitation->role }}.</p>

                    @if ($errors->any())
                        <x-monitor::alert color="rose" class="mt-4">
                            {{ $errors->first() }}
                        </x-monitor::alert>
                    @endif

                    <form method="POST" action="{{ route('monitor.invitations.store', $token) }}" class="mt-4 space-y-4">
                        @csrf
                        <div>
                            <label for="name" class="block font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.auth.name') }}</label>
                            <input type="text" name="name" id="name" value="{{ old('name') }}" required autofocus
                                   class="mt-1 w-full rounded-xl bg-neutral-200 px-3 py-2 text-sm text-neutral-800 shadow-neu-inset dark:bg-neutral-800 dark:text-neutral-100 dark:shadow-neu-dark-inset">
                        </div>
                        <div>
                            <label for="password" class="block font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.auth.password') }}</label>
                            <input type="password" name="password" id="password" required
                                   class="mt-1 w-full rounded-xl bg-neutral-200 px-3 py-2 text-sm text-neutral-800 shadow-neu-inset dark:bg-neutral-800 dark:text-neutral-100 dark:shadow-neu-dark-inset">
                        </div>
                        <div>
                            <label for="password_confirmation" class="block font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.auth.confirm_password') }}</label>
                            <input type="password" name="password_confirmation" id="password_confirmation" required
                                   class="mt-1 w-full rounded-xl bg-neutral-200 px-3 py-2 text-sm text-neutral-800 shadow-neu-inset dark:bg-neutral-800 dark:text-neutral-100 dark:shadow-neu-dark-inset">
                        </div>
                        <button type="submit" class="w-full rounded-xl bg-blue-600 dark:bg-purple-600 py-2 text-sm font-medium text-white shadow-neu-sm hover:bg-blue-500 dark:hover:bg-purple-500 active:scale-[0.98]">{{ __('monitor::messages.auth.create_account') }}</button>
                    </form>
                @endif
            </div>
        </div>
    </div>
</x-monitor::layout>
