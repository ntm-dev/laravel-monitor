<?php

namespace LaravelMonitor\Livewire;

use LaravelMonitor\Support\QueryConnection;

class QueryDetail extends Card
{
    public const PER_PAGE = 25;

    public string $key = '';

    public int $page = 1;

    public function mount(?string $period = null, ?string $from = null, ?string $to = null, ?string $key = null): void
    {
        parent::mount($period, $from, $to);

        $this->key = $key ?? (string) request('key', '');
    }

    public function previousPage(): void
    {
        $this->page = max(1, $this->page - 1);
    }

    public function nextPage(): void
    {
        $this->page++;
    }

    protected function view(): string
    {
        return 'monitor::livewire.query-detail';
    }

    protected function data(): array
    {
        $since = $this->since();
        $until = $this->until();
        $storage = $this->aggregateStorage();
        $timelineStorage = $this->timelineStorage();
        $buckets = $this->chartBuckets();
        $key = $this->key;

        $stats = $storage->stats('query', $since, null, $key, $until);

        $totalEntries = $stats->count;
        $lastPage = max(1, (int) ceil($totalEntries / self::PER_PAGE));
        $page = min(max(1, $this->page), $lastPage);

        $entries = $timelineStorage->recent('query', $since, self::PER_PAGE, null, $key, $until, ($page - 1) * self::PER_PAGE);

        // request_id may belong to a request, job, command or scheduled task;
        // resolve each row's source in one batched lookup.
        $requestIds = $entries->pluck('request_id')->filter()->unique()->values()->all();
        $rootTypes = $timelineStorage->rootTypesFor($requestIds);
        $rootLabels = $timelineStorage->rootLabelsFor($requestIds);

        $entries = $entries->map(function ($entry) use ($rootTypes, $rootLabels) {
            $entry->sourceType = $rootTypes->get($entry->request_id);
            $entry->sourceLabel = $entry->sourceType !== null ? $rootLabels->get($entry->request_id) : null;
            $entry->sourceUrl = match ($entry->sourceType) {
                'request' => route('monitor.requests.show', $entry->request_id),
                'job' => route('monitor.jobs.attempts.show', $entry->request_id),
                'command' => route('monitor.commands.runs.show', $entry->request_id),
                'scheduled_task' => route('monitor.schedule.runs.show', $entry->request_id),
                default => null,
            };
            [$entry->connection, $entry->connectionType] = QueryConnection::parse($entry->subtype);

            return $entry;
        });

        // $key is the normalized shape (Sql::normalizeKey()); the header shows
        // the newest call's real SQL and trace, independent of the current page.
        $latest = $timelineStorage->recent('query', $since, 1, null, $key, $until)->first();
        $sql = $latest !== null ? ($latest->payload['sql'] ?? $key) : $key;
        // Null unless the recorder's 'trace' option is on (default: local env only).
        $trace = $latest !== null ? ($latest->payload['trace'] ?? null) : null;

        // "Slow" is judged at render time against the live config threshold.
        $slowThreshold = (float) config('monitor.recorders.'.\LaravelMonitor\Recorders\Queries::class.'.threshold', 100);

        return [
            'calls' => $stats->count,
            'totalTime' => $stats->total_duration,
            'callBuckets' => $storage->countsPerBucket('query', $since, $buckets, null, $key, $until),
            'duration' => $storage->durationStats('query', $since, $buckets, $key, null, $until),
            'entries' => $entries,
            'sql' => $sql,
            'trace' => $trace,
            'firstSeen' => $timelineStorage->firstSeen('query', $key),
            // From the loaded page only: one row per connection name with
            // every connection type (e.g. read/write) its calls used.
            'connections' => $entries
                ->pluck('connection')
                ->filter()
                ->unique()
                ->sort()
                ->values()
                ->map(function (string $connection) use ($entries) {
                    $types = $entries
                        ->where('connection', $connection)
                        ->pluck('connectionType')
                        ->filter()
                        ->unique()
                        ->sort()
                        ->values()
                        ->all();

                    return ['name' => $connection, 'types' => $types];
                }),
            'totalEntries' => $totalEntries,
            'page' => $page,
            'lastPage' => $lastPage,
            'perPage' => self::PER_PAGE,
            'slowThreshold' => $slowThreshold,
        ];
    }
}
