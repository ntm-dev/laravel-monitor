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

    /** Per-poll cap on topUp(); any surplus catches up on the next poll. */
    protected const TOP_UP_LIMIT = 200;

    public string $level = '';

    public string $userId = '';

    /**
     * Keyset cursor over the rows on screen. Only these ids are component
     * state; new batches are dispatched as rendered HTML so the snapshot
     * stays small however far the user scrolls.
     */
    public ?int $oldestId = null;

    public ?int $newestId = null;

    public bool $hasMore = true;

    /**
     * Whether data() has fetched the first batch. Public so Livewire persists it.
     */
    public bool $bootstrapped = false;

    /**
     * Rows reload() just fetched, consumed by data() in the same request
     * instead of a redundant topUp(). Not persisted.
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

    public function updatedLevel(): void
    {
        $this->reload();
    }

    public function updatedUserId(): void
    {
        $this->reload();
    }

    /** Refetch the first page under the current filters and replace the browser's list. */
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
            // Later renders only top up; $logs stays empty since the list is
            // wire:ignore'd after the first paint.
            $this->topUp();
            $logs = collect();
        }

        return [
            'logs' => $logs,
            'users' => $this->userFilterOptions('log', $this->since(), $this->until()),
            'hasMore' => $this->hasMore,
        ];
    }

    /** Fetch rows newer than $newestId and dispatch them for the browser to prepend. */
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
     * Add the view fields logs-entry.blade.php reads (source link, level,
     * summary, ...), batching the source lookups per batch.
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

    /** Render a present()ed batch with logs-entry.blade.php for a monitor-logs-* dispatch. */
    protected function renderRowsHtml(Collection $presented): string
    {
        return $presented
            ->map(static fn ($log) => view('monitor::livewire.logs-entry', ['log' => $log])->render())
            ->implode('');
    }

    /**
     * renderRowsHtml() for monitor-logs-replace, which replaces the whole list,
     * so an empty batch renders the empty state instead.
     */
    protected function renderListHtml(Collection $presented): string
    {
        if ($presented->isEmpty()) {
            // Same #monitor-logs-empty id as logs.blade.php so a later prepend removes it.
            return '<div id="monitor-logs-empty">'.view('monitor::components.empty-state', [
                'label' => __('monitor::messages.nav.logs'),
                'message' => __('monitor::messages.common.no_log_entries'),
                'periodPhrase' => $this->periodPhrase(),
            ])->render().'</div>';
        }

        return $this->renderRowsHtml($presented);
    }
}
