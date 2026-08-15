<?php

namespace LaravelMonitor\Livewire;

class Logs extends Card
{
    public string $level = '';

    protected function view(): string
    {
        return 'monitor::livewire.logs';
    }

    protected function data(): array
    {
        $storage = $this->storage();
        $logs = $storage->recent('log', $this->since(), $this->limit, $this->level ?: null, null, $this->until());

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

            return $log;
        });

        return [
            'logs' => $logs,
        ];
    }
}
