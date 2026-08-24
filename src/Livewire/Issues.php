<?php

namespace LaravelMonitor\Livewire;

use Illuminate\Support\Collection;
use LaravelMonitor\Contracts\Storage;
use LaravelMonitor\Livewire\Concerns\SyncsOpenIssues;
use LaravelMonitor\Support\Format;
use Livewire\Attributes\Url;

use function count;

class Issues extends Card
{
    use SyncsOpenIssues;

    /**
     * Areas checked against their own configured threshold for the
     * Performance tab, in the order rows fall back to when max durations tie.
     * type => the monitor_entries `type`; tab => dashboard tab a row links to.
     */
    public const PERFORMANCE_AREAS = [
        'request' => ['badge' => 'Request', 'tab' => 'requests', 'threshold' => 'request'],
        'job' => ['badge' => 'Job', 'tab' => 'jobs', 'threshold' => 'job'],
        'query' => ['badge' => 'Query', 'tab' => 'queries', 'threshold' => 'query'],
        'outgoing_request' => ['badge' => 'Outgoing', 'tab' => 'outgoing', 'threshold' => 'outgoing_request'],
        'command' => ['badge' => 'Command', 'tab' => 'commands', 'threshold' => 'command'],
    ];

    public const STATUSES = ['open', 'resolved', 'ignored'];

    /** Ordinal rank for sort('priority') — higher sorts first in 'desc'. */
    protected const PRIORITY_RANK = ['none' => 0, 'low' => 1, 'medium' => 2, 'high' => 3, 'urgent' => 4];

    /**
     * Columns sort() accepts — 'first_seen' only exists on exception rows
     * and 'max_duration' only on performance rows, but sorting the other
     * collection by a key it doesn't have is a harmless no-op (every row
     * ties), so both tables share one $sortBy/$sortDirection pair instead
     * of needing one each.
     */
    protected const SORTABLE = ['priority', 'id', 'label', 'count', 'users', 'first_seen', 'last_seen', 'max_duration'];

    public const PER_PAGE = 25;

    public string $view = 'exceptions';

    public string $status = 'open';

    public string $sortBy = 'id';

    public string $sortDirection = 'desc';

    /**
     * Kept in the URL (not just server-side state) so that navigating to an
     * issue's detail page and back restores the same filtered list the
     * search box's own text still shows — without this, a fresh mount on
     * the way back resets $search to '' server-side while the <input>'s
     * value survives regardless, purely via the browser's own form-field
     * history restoration, leaving the two visibly out of sync.
     */
    #[Url]
    public string $search = '';

    public int $page = 1;

    public function resolve(string $type, string $key): void
    {
        $this->setStatus($type, $key, 'resolved');
        $this->notify('success', trans_choice('monitor::messages.issue.toast_resolved', 1));
    }

    public function ignore(string $type, string $key): void
    {
        $this->setStatus($type, $key, 'ignored');
        $this->notify('success', trans_choice('monitor::messages.issue.toast_ignored', 1));
    }

    public function reopen(string $type, string $key): void
    {
        $this->setStatus($type, $key, 'open');
    }

    /**
     * Set an issue's priority straight from the list's own dropdown —
     * mirrors resolve()/ignore()/reopen()'s inline pattern instead of
     * requiring a trip to the Issue detail page for this one field.
     */
    public function setPriority(string $type, string $key, string $priority): void
    {
        if (! array_key_exists($type, self::PERFORMANCE_AREAS) && $type !== 'exception') {
            return;
        }

        $current = $this->storage()->issueStatuses($type, [$key])->get($key);

        if ($current !== null && $current->priority === $priority) {
            return;
        }

        $this->storage()->setIssuePriority($type, $key, $priority);

        $id = $current->id ?? $this->storage()->issueStatuses($type, [$key])->get($key)->id;

        $this->notify('success', __('monitor::messages.issue.toast_priority_updated_list', ['id' => $id, 'level' => Format::priorityLabel($priority)]));
    }

    public function sort(string $column): void
    {
        if (! in_array($column, self::SORTABLE, true)) {
            return;
        }

        if ($this->sortBy === $column) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDirection = 'desc';
        }

        $this->page = 1;
    }

    public function previousPage(): void
    {
        $this->page = max(1, $this->page - 1);
    }

    public function nextPage(): void
    {
        $this->page++;
    }

    public function updatedSearch(): void
    {
        $this->page = 1;
    }

    /**
     * $pairs is entirely client-owned (Alpine, see issues.blade.php) — which
     * rows are checked never touches the server until a bulk action fires,
     * so a plain checkbox click no longer costs a Livewire round-trip.
     *
     * @param  array<int, array{0: string, 1: string}>  $pairs
     */
    public function resolveSelected(array $pairs): void
    {
        $this->applyStatusToSelected($pairs, 'resolved');
        $this->notify('success', trans_choice('monitor::messages.issue.toast_resolved', count($pairs), ['count' => count($pairs)]));
    }

    /**
     * @param  array<int, array{0: string, 1: string}>  $pairs
     */
    public function ignoreSelected(array $pairs): void
    {
        $this->applyStatusToSelected($pairs, 'ignored');
        $this->notify('success', trans_choice('monitor::messages.issue.toast_ignored', count($pairs), ['count' => count($pairs)]));
    }

    /**
     * Resets the sort back to each tab's own natural order — newest issue
     * first for Exceptions, worst offender first for Performance — since
     * both tabs share the same $sortBy/$sortDirection pair, and 'id' (the
     * other tab's default) has no relationship to a performance row's
     * severity.
     */
    public function updatedView(): void
    {
        $this->page = 1;
        $this->sortBy = $this->view === 'performance' ? 'max_duration' : 'id';
        $this->sortDirection = 'desc';
    }

    public function updatedStatus(): void
    {
        $this->page = 1;
    }

    /**
     * @param  array<int, array{0: string, 1: string}>  $pairs
     */
    protected function applyStatusToSelected(array $pairs, string $status): void
    {
        foreach ($pairs as [$type, $key]) {
            $this->setStatus($type, $key, $status);
        }
    }

    protected function setStatus(string $type, string $key, string $status): void
    {
        if (! array_key_exists($type, self::PERFORMANCE_AREAS) && $type !== 'exception') {
            return;
        }

        $this->storage()->setIssueStatus($type, $key, $status);
    }

    protected function view(): string
    {
        return 'monitor::livewire.issues';
    }

    protected function data(): array
    {
        $since = $this->since();
        $until = $this->until();
        $storage = $this->storage();

        $openIssueCountBefore = $storage->openIssueCount();

        [$exceptions, $performance] = $this->syncOpenIssues($storage, $since, $until);

        $openIssueCount = $storage->openIssueCount();

        // syncOpenIssues() above can open a brand-new issue or reopen a
        // recurring one on its own, with no explicit resolve/ignore/reopen
        // call to carry the same nudge setStatus() would send — and
        // resolve()/ignore()/etc. land here too, one render() later. Only
        // dispatching when the count actually moved (rather than on every
        // render) keeps the sidebar's OpenIssueBadge in sync without also
        // firing a second request on every search keystroke, sort, or page
        // change that doesn't touch it.
        if ($openIssueCount !== $openIssueCountBefore) {
            $this->dispatch('issues-changed');
        }

        // exceptionGroups() (not recent()'s fixed-size "most recent entries"
        // window) so that an old-but-still-open group whose own latest entry
        // has aged out of a small global sample still resolves its class
        // name instead of falling back to the raw fingerprint key — see
        // Exceptions::data(), which already relies on this for the same
        // reason.
        $latest = $storage->exceptionGroups($since, $until)->keyBy('key');

        $exceptions = $exceptions->map(function ($group) use ($latest) {
            $found = $latest->get($group->key);
            $group->latest = ['class' => $found->class ?? $group->key, 'message' => $found->message ?? null];
            $group->label = $group->latest['class'];

            return $group;
        });

        $exceptions = $this->attachIssueStatus($storage, 'exception', $exceptions);

        $performance = $performance->groupBy('type')
            ->flatMap(fn ($items, $type) => $this->attachIssueStatus($storage, $type, $items))
            ->values();

        $status = in_array($this->status, self::STATUSES, true) ? $this->status : 'open';

        $exceptions = $exceptions->where('status', $status)->values();
        $performance = $performance->where('status', $status)->values();

        if ($this->search !== '') {
            $needle = $this->search;

            $exceptions = $exceptions
                ->filter(fn ($group) => stripos($group->key, $needle) !== false
                    || stripos($group->latest['class'] ?? '', $needle) !== false
                    || stripos($group->latest['message'] ?? '', $needle) !== false)
                ->values();
            $performance = $performance
                ->filter(fn ($item) => stripos($item->label, $needle) !== false)
                ->values();
        }

        if (in_array($this->sortBy, self::SORTABLE, true)) {
            $key = $this->sortBy === 'priority'
                ? fn ($row) => self::PRIORITY_RANK[$row->priority] ?? 0
                : $this->sortBy;
            $descending = $this->sortDirection === 'desc';

            $exceptions = $exceptions->sortBy($key, SORT_REGULAR, $descending)->values();
            $performance = $performance->sortBy($key, SORT_REGULAR, $descending)->values();
        }

        $exceptionCount = $exceptions->count();
        $performanceCount = $performance->count();

        // Paginating the currently active tab only — the other one's count
        // above already reflects its own full filtered total for the tab
        // button's badge, so slicing it too would serve no purpose.
        $total = $this->view === 'exceptions' ? $exceptionCount : $performanceCount;
        $lastPage = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min(max(1, $this->page), $lastPage);
        $from = ($page - 1) * self::PER_PAGE;

        if ($this->view === 'exceptions') {
            $exceptions = $exceptions->slice($from, self::PER_PAGE)->values();
        } else {
            $performance = $performance->slice($from, self::PER_PAGE)->values();
        }

        return [
            'exceptions' => $exceptions,
            'exceptionCount' => $exceptionCount,
            'performance' => $performance,
            'performanceCount' => $performanceCount,
            'openIssueCount' => $openIssueCount,
            'status' => $status,
            'total' => $total,
            'page' => $page,
            'lastPage' => $lastPage,
            'perPage' => self::PER_PAGE,
            'from' => $from,
        ];
    }

    /**
     * Attaches each item's persisted status + first_seen (defaulting to
     * "open"/its own last_seen for one syncIssues() hasn't recorded yet —
     * shouldn't happen in practice since data() always syncs immediately
     * before calling this, but keeps the view safe either way).
     */
    protected function attachIssueStatus(Storage $storage, string $type, Collection $items): Collection
    {
        $statuses = $storage->issueStatuses($type, $items->pluck('key')->unique()->all());

        return $items->map(function ($item) use ($statuses, $type) {
            $found = $statuses->get($item->key);
            $item->issue_type = $type;
            $item->id = $found->id ?? null;
            $item->uuid = $found->uuid ?? null;
            $item->priority = $found->priority ?? 'none';
            $item->status = $found->status ?? 'open';
            $item->first_seen = $found->first_seen ?? $item->last_seen;

            return $item;
        });
    }
}
