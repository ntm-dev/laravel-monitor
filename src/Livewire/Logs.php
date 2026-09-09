<?php

namespace LaravelMonitor\Livewire;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use LaravelMonitor\Livewire\Concerns\ResolvesUserNames;
use LaravelMonitor\Support\JsonTree;

use function str_replace;

class Logs extends Card
{
    use ResolvesUserNames;

    protected const DEFAULT_LIMIT = 50;

    protected const LOAD_MORE_STEP = 20;

    /**
     * Cap on the auto-refresh top-up fetch — bounds the (rare) burst case
     * where more entries than this land inside a single poll interval; the
     * surplus simply catches up on the next poll instead of blocking it.
     */
    protected const TOP_UP_LIMIT = 200;

    public string $level = '';

    public string $userId = '';

    /**
     * The keyset cursor: id of the oldest/newest row currently on screen.
     * This — not the rows themselves — is all Livewire tracks for the list,
     * so its wire:snapshot stays a fixed, tiny size no matter how far the
     * user has scrolled. loadMore()/data() page from these ids instead of an
     * ever-growing limit/offset, and dispatch each new batch's rendered HTML
     * to the browser (see logs.blade.php) rather than returning it through
     * $logs on every request — a Livewire property holding the whole,
     * potentially very long, accumulated list gets re-serialized on every
     * single request (every poll, every action), which is what was driving
     * the page progressively slower and eventually past PHP's memory limit.
     */
    public ?int $oldestId = null;

    public ?int $newestId = null;

    public bool $hasMore = true;

    /**
     * Whether the first batch has been fetched yet — see data(). Must stay
     * `public`: Livewire only persists public properties across requests, and
     * this needs to read `true` on every request after the first or data()
     * would re-take the bootstrap branch forever and never top up.
     */
    public bool $bootstrapped = false;

    /**
     * Set by reload() for data() to pick up on that same request, so a
     * level/userId change both re-renders the browser's list (via the
     * dispatched replace event) and reflects the new scope in $logs, without
     * data()'s usual top-up fetch redundantly re-querying what reload() just
     * fetched. Never persisted — irrelevant beyond the request it's set in.
     */
    protected ?Collection $pendingLogs = null;

    /** Fetches the next older batch and dispatches it for the browser to append below the list. */
    public function loadMore(): void
    {
        if (! $this->hasMore) {
            return;
        }

        $older = $this->timelineStorage()->recent(
            'log', $this->since(), self::LOAD_MORE_STEP, $this->level ?: null, null, $this->until(),
            userId: $this->scopedUserId(), beforeId: $this->oldestId,
        );

        $this->hasMore = $older->count() >= self::LOAD_MORE_STEP;

        if ($older->isEmpty()) {
            return;
        }

        $this->oldestId = $older->last()->id;
        $this->dispatch('monitor-logs-append', html: $this->renderRowsHtml($this->present($older)));
    }

    /** Resets back to a fresh first page whenever the level filter changes, so switching filters doesn't carry over rows loaded under the old one. */
    public function updatedLevel(): void
    {
        $this->reload();
    }

    public function updatedUserId(): void
    {
        $this->reload();
    }

    /** Refetches the first page under the current filters and dispatches it to replace whatever the browser has on screen. */
    protected function reload(): void
    {
        $initial = $this->timelineStorage()->recent('log', $this->since(), self::DEFAULT_LIMIT, $this->level ?: null, null, $this->until(), userId: $this->scopedUserId());

        $this->hasMore = $initial->count() >= self::DEFAULT_LIMIT;
        $this->newestId = $initial->first()?->id;
        $this->oldestId = $initial->last()?->id;

        $this->pendingLogs = $this->present($initial);
        $this->dispatch('monitor-logs-replace', html: $this->renderListHtml($this->pendingLogs));
    }

    protected function view(): string
    {
        return 'monitor::livewire.logs';
    }

    protected function data(): array
    {
        if (! $this->bootstrapped) {
            $this->bootstrapped = true;

            $initial = $this->timelineStorage()->recent('log', $this->since(), self::DEFAULT_LIMIT, $this->level ?: null, null, $this->until(), userId: $this->scopedUserId());

            $this->hasMore = $initial->count() >= self::DEFAULT_LIMIT;
            $this->newestId = $initial->first()?->id;
            $this->oldestId = $initial->last()?->id;

            $logs = $this->present($initial);
        } elseif ($this->pendingLogs !== null) {
            $logs = $this->pendingLogs;
            $this->pendingLogs = null;
        } else {
            // Every later render (mainly the data-monitor-poll auto-refresh)
            // only tops the list up with anything inserted since — see
            // topUp(). $logs stays empty: #monitor-logs-list is wire:ignore'd
            // in the view, so Blade's own output for it is never applied
            // past the very first paint above.
            $this->topUp();
            $logs = collect();
        }

        return [
            'logs' => $logs,
            'users' => $this->userFilterOptions('log', $this->since(), $this->until()),
            'hasMore' => $this->hasMore,
        ];
    }

    /** Fetches anything inserted since the newest row already on screen and dispatches it to prepend above the list. */
    protected function topUp(): void
    {
        $newer = $this->timelineStorage()->recent(
            'log', $this->since(), self::TOP_UP_LIMIT, $this->level ?: null, null, $this->until(),
            userId: $this->scopedUserId(), afterId: $this->newestId,
        );

        if ($newer->isEmpty()) {
            return;
        }

        $this->newestId = $newer->first()->id;
        $this->oldestId ??= $newer->last()->id;

        $this->dispatch('monitor-logs-prepend', html: $this->renderRowsHtml($this->present($newer)));
    }

    protected function scopedUserId(): ?string
    {
        return $this->userId !== '' ? $this->userId : null;
    }

    /**
     * Adds the view-ready fields (source link, level, decoded/summarized
     * message, ...) that logs-entry.blade.php reads, batching the
     * rootTypesFor()/rootLabelsFor() lookups across just this one batch —
     * same pattern as QueryDetail/BuildsExceptionDetail::occurrenceRows().
     */
    protected function present(Collection $rows): Collection
    {
        $storage = $this->timelineStorage();
        $requestIds = $rows->pluck('request_id')->filter()->unique()->values()->all();
        $rootTypes = $storage->rootTypesFor($requestIds);
        $rootLabels = $storage->rootLabelsFor($requestIds);

        return $rows->map(static function ($log) use ($rootTypes, $rootLabels) {
            $log->sourceType = $rootTypes->get($log->request_id);
            $log->sourceLabel = $log->sourceType !== null ? $rootLabels->get($log->request_id) : null;
            $log->sourceUrl = match ($log->sourceType) {
                'request' => route('monitor.requests.show', $log->request_id),
                'job' => route('monitor.jobs.attempts.show', $log->request_id),
                'command' => route('monitor.commands.runs.show', $log->request_id),
                'scheduled_task' => route('monitor.schedule.runs.show', $log->request_id),
                default => null,
            };

            $log->level = $log->subtype ?? 'info';
            $contextRaw = $log->payload['context'] ?? '{}';
            $message = $log->payload['message'] ?? '';
            $log->contextRaw = $contextRaw;
            $log->contextTree = JsonTree::parse($contextRaw);
            $log->summary = $message !== ''
                ? $message
                : Str::limit(str_replace(["\r\n", "\n", "\r"], ' ', $contextRaw), 200);

            return $log;
        });
    }

    /** Renders an already-present()ed batch to the same markup logs-entry.blade.php's @include produces, for a monitor-logs-* dispatch. */
    protected function renderRowsHtml(Collection $presented): string
    {
        return $presented
            ->map(static fn ($log) => view('monitor::livewire.logs-entry', ['log' => $log])->render())
            ->implode('');
    }

    /**
     * Like renderRowsHtml(), but for monitor-logs-replace: an empty batch
     * there means the new filter matched nothing, and the browser is about
     * to overwrite #monitor-logs-list's entire content wholesale — so unlike
     * an append/prepend, an empty result here still needs to render
     * *something*, or the list would go visually blank instead of showing
     * the empty-state message logs.blade.php's own @empty branch would have.
     */
    protected function renderListHtml(Collection $presented): string
    {
        if ($presented->isEmpty()) {
            // Wrapped in the same #monitor-logs-empty id logs.blade.php's own
            // @empty branch uses, so a later monitor-logs-prepend (a log
            // arriving under this now-empty scope) still finds and removes it.
            return '<div id="monitor-logs-empty">'.view('monitor::components.empty-state', [
                'label' => __('monitor::messages.nav.logs'),
                'message' => __('monitor::messages.common.no_log_entries'),
                'periodPhrase' => $this->periodPhrase(),
            ])->render().'</div>';
        }

        return $this->renderRowsHtml($presented);
    }
}
