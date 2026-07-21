{{-- Invite-acceptance page. See Http\Controllers\Auth\InvitationController. --}}
<x-monitor::layout title="Accept invitation">
    <div class="flex min-h-screen items-center justify-center bg-neutral-50 px-4 dark:bg-neutral-950">
        <div class="w-full max-w-sm">
            <div class="mb-6 flex items-center justify-center gap-2.5">
                <span class="flex h-9 w-9 items-center justify-center rounded-md bg-neutral-900 text-sm font-semibold text-white dark:bg-neutral-700">{{ strtoupper(mb_substr(config('app.name', 'L'), 0, 1)) }}</span>
                <span class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">{{ config('app.name', 'Laravel') }} Monitor</span>
            </div>

            <div class="rounded-lg border border-neutral-200 bg-white p-6 shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                @if ($expired)
                    <h1 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">This invitation has expired</h1>
                    <p class="mt-1 text-sm text-neutral-500 dark:text-neutral-400">Ask an owner or admin to invite {{ $invitation->email }} again.</p>
                @else
                    <h1 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">Join {{ config('app.name', 'Laravel') }} Monitor</h1>
                    <p class="mt-1 text-sm text-neutral-500 dark:text-neutral-400">Create your account for <strong>{{ $invitation->email }}</strong> as {{ $invitation->role }}.</p>

                    @if ($errors->any())
                        <div class="mt-4 rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-600 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-400">
                            {{ $errors->first() }}
                        </div>
                    @endif

                    <form method="POST" action="{{ route('monitor.invitations.store', $token) }}" class="mt-4 space-y-4">
                        @csrf
                        <div>
                            <label for="name" class="block font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400">Name</label>
                            <input type="text" name="name" id="name" value="{{ old('name') }}" required autofocus
                                   class="mt-1 w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-900 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100">
                        </div>
                        <div>
                            <label for="password" class="block font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400">Password</label>
                            <input type="password" name="password" id="password" required
                                   class="mt-1 w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-900 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100">
                        </div>
                        <div>
                            <label for="password_confirmation" class="block font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400">Confirm password</label>
                            <input type="password" name="password_confirmation" id="password_confirmation" required
                                   class="mt-1 w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-900 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100">
                        </div>
                        <button type="submit" class="w-full rounded-md bg-blue-600 py-2 text-sm font-medium text-white hover:bg-blue-500">Create account</button>
                    </form>
                @endif
            </div>
        </div>
    </div>
</x-monitor::layout>
