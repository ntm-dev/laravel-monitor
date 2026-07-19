{{-- Status + Priority controls for the standalone Issue detail page.
     Plain POST-and-redirect forms (see Http\Controllers\IssueController),
     not Livewire — matches the SettingsController convention already used
     for infrequent, non-reactive mutations on this dashboard. --}}
@props(['issue', 'statuses', 'priorities'])
<div class="rounded-lg border border-neutral-200 bg-white shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
    <p class="border-b border-neutral-100 dark:border-neutral-800 px-4 py-3 font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400">Manage</p>

    <div class="space-y-4 p-4">
        <div>
            <label class="block font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400">Status</label>
            <form method="POST" action="{{ route('monitor.issues.status', $issue->uuid) }}" class="mt-2 flex gap-1 rounded-lg border border-neutral-200 dark:border-neutral-700 bg-neutral-50 dark:bg-neutral-800/50 p-0.5">
                @csrf
                @foreach ($statuses as $status)
                    <button type="submit" name="status" value="{{ $status }}"
                            @class([
                                'flex-1 rounded-md px-2 py-1 text-xs capitalize',
                                'bg-white dark:bg-neutral-900 text-neutral-900 dark:text-neutral-100 shadow-sm' => $issue->status === $status,
                                'text-neutral-500 dark:text-neutral-400 hover:text-neutral-900 dark:hover:text-neutral-100' => $issue->status !== $status,
                            ])>{{ $status }}</button>
                @endforeach
            </form>
        </div>

        <div>
            <label for="priority" class="block font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400">Priority</label>
            <form method="POST" action="{{ route('monitor.issues.priority', $issue->uuid) }}" class="mt-2">
                @csrf
                <select name="priority" id="priority" onchange="this.form.submit()"
                        class="w-full rounded-md border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 px-2 py-1.5 text-sm text-neutral-700 dark:text-neutral-200 focus:outline-none">
                    @foreach ($priorities as $value => $label)
                        <option value="{{ $value }}" @selected($issue->priority === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </form>
        </div>
    </div>
</div>
