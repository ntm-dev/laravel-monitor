<?php

namespace LaravelMonitor\Livewire;

use Illuminate\Support\Str;
use LaravelMonitor\Support\JsonTree;

use function str_replace;

class Logs extends Card
{
    protected const DEFAULT_LIMIT = 50;

    protected const LOAD_MORE_STEP = 20;

    public string $level = '';

    public string $userId = '';

    public int $limit = self::DEFAULT_LIMIT;

    /** Bumps how many rows the next render fetches — called by the sentinel at the bottom of the list (x-intersect in logs.blade.php) instead of a wire:click "Load more" button. */
    public function loadMore(): void
    {
        $this->limit += self::LOAD_MORE_STEP;
    }

    /** Resets back to the first page's worth of rows whenever the level filter changes, so switching filters doesn't carry over however far the user had scrolled under the old one. */
    public function updatedLevel(): void
    {
        $this->limit = self::DEFAULT_LIMIT;
    }

    public function updatedUserId(): void
    {
        $this->limit = self::DEFAULT_LIMIT;
    }

    protected function view(): string
    {
        return 'monitor::livewire.logs';
    }

    protected function data(): array
    {
        $storage = $this->timelineStorage();
        $userId = $this->userId !== '' ? $this->userId : null;
        $logs = $storage->recent('log', $this->since(), $this->limit, $this->level ?: null, null, $this->until(), userId: $userId);

        // Same batched rootTypesFor()/rootLabelsFor() pair as
        // QueryDetail/BuildsExceptionDetail::occurrenceRows() — one query
        // pair for the whole page instead of a findByRequestId() per row.
        $requestIds = $logs->pluck('request_id')->filter()->unique()->values()->all();
        $rootTypes = $storage->rootTypesFor($requestIds);
        $rootLabels = $storage->rootLabelsFor($requestIds);

        $logs = $logs->map(function ($log) use ($rootTypes, $rootLabels) {
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

        return [
            'logs' => $logs,
            // Fewer rows than asked for means storage has run out — the
            // sentinel in logs.blade.php only renders while this is true.
            'hasMore' => $logs->count() >= $this->limit,
        ];
    }
}
