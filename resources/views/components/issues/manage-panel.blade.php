{{-- Status + Priority controls for the standalone Issue detail page.
     Plain POST-and-redirect forms (see Http\Controllers\IssueController),
     not Livewire — matches the SettingsController convention already used
     for infrequent, non-reactive mutations on this dashboard. --}}
@props(['issue', 'statuses', 'priorities'])
<div class="rounded-2xl bg-neutral-200 shadow-neu dark:bg-neutral-800 dark:shadow-neu-dark">
    <p class="shadow-[0_1px_0_rgba(0,0,0,0.06)] dark:shadow-[0_1px_0_rgba(255,255,255,0.06)] px-4 py-3 font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.issue.manage') }}</p>

    <div class="space-y-4 p-4">
        <div>
            <label class="block font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.common.status') }}</label>
            <form method="POST" action="{{ route('monitor.issues.status', $issue->uuid) }}" class="mt-2 flex gap-1 rounded-xl bg-neutral-200 dark:bg-neutral-800 p-0.5 shadow-neu-inset dark:shadow-neu-dark-inset">
                @csrf
                @foreach ($statuses as $status)
                    <button type="submit" name="status" value="{{ $status }}"
                            @class([
                                'flex-1 rounded-lg px-2 py-1 text-xs capitalize',
                                'bg-neutral-200 dark:bg-neutral-800 text-neutral-900 dark:text-neutral-100 shadow-neu-sm dark:shadow-neu-dark-sm' => $issue->status === $status,
                                'text-neutral-500 dark:text-neutral-400 hover:text-neutral-900 dark:hover:text-neutral-100' => $issue->status !== $status,
                            ])>{{ $status }}</button>
                @endforeach
            </form>
        </div>

        <div>
            <label for="priority" class="block font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.issue.priority') }}</label>
            <form method="POST" action="{{ route('monitor.issues.priority', $issue->uuid) }}" class="mt-2">
                @csrf
                <select name="priority" id="priority" onchange="this.form.submit()"
                        class="w-full rounded-xl bg-neutral-200 dark:bg-neutral-800 px-2 py-1.5 text-sm text-neutral-700 dark:text-neutral-200 shadow-neu-inset dark:shadow-neu-dark-inset">
                    @foreach ($priorities as $value => $label)
                        <option value="{{ $value }}" @selected($issue->priority === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </form>
        </div>
    </div>
</div>
