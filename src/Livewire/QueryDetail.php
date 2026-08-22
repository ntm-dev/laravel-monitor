<?php

namespace LaravelMonitor\Livewire;

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
        $storage = $this->storage();
        $buckets = $this->chartBuckets();
        $key = $this->key;

        $stats = $storage->stats('query', $since, null, $key, $until);

        $totalEntries = $stats->count;
        $lastPage = max(1, (int) ceil($totalEntries / self::PER_PAGE));
        $page = min(max(1, $this->page), $lastPage);

        $entries = $storage->recent('query', $since, self::PER_PAGE, null, $key, $until, ($page - 1) * self::PER_PAGE);

        // One batched rootTypesFor()/rootLabelsFor() pair instead of a
        // findByRequestId() per row — same approach as
        // BuildsExceptionDetail::occurrenceRows(). request_id is a generic
        // correlation id shared by requests, jobs, commands, and scheduled
        // tasks, so it takes both calls (not just a 'request'-only lookup)
        // to know which detail-page route a call actually belongs to.
        $requestIds = $entries->pluck('request_id')->filter()->unique()->values()->all();
        $rootTypes = $storage->rootTypesFor($requestIds);
        $rootLabels = $storage->rootLabelsFor($requestIds);

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

            return $entry;
        });

        // $key is the normalized grouping shape (see Support\Sql::normalizeKey())
        // — placeholders collapsed, not real SQL a database ever ran. The header
        // card shows the actual text of the most recent call instead, fetched
        // independently of $entries so it stays fixed to the newest call
        // regardless of which page of the calls table is showing. Falls back
        // to $key only when no entry is loaded to take it from.
        $latest = $storage->recent('query', $since, 1, null, $key, $until)->first();
        $sql = $latest !== null ? ($latest->payload['sql'] ?? $key) : $key;

        // Recorders\Queries no longer tags a slow/fast subtype at record
        // time (a config change after the fact would leave old rows
        // mislabeled against today's setting anyway) — compare each row's
        // actual duration against the live threshold instead, so the
        // "slow" cue on this page is always current.
        $slowThreshold = (float) config('monitor.recorders.'.\LaravelMonitor\Recorders\Queries::class.'.threshold', 100);

        return [
            'calls' => $stats->count,
            'totalTime' => $stats->total_duration,
            'callBuckets' => $storage->countsPerBucket('query', $since, $buckets, null, $key, $until),
            'duration' => $storage->durationStats('query', $since, $buckets, $key, null, $until),
            'entries' => $entries,
            'sql' => $sql,
            'firstSeen' => $storage->firstSeen('query', $key),
            // Derived from the loaded page of entries (not the full period)
            // — a quick summary, not an exhaustive audit. One row per
            // distinct connection name; 'type' is only set when every
            // sampled call against that connection agreed on the same PDO
            // role (see DatabaseStorage::queryStats() for why a connection
            // can carry more than one).
            'connections' => $entries
                ->pluck('payload.connection')
                ->filter()
                ->unique()
                ->sort()
                ->values()
                ->map(function (string $connection) use ($entries) {
                    $types = $entries
                        ->where('payload.connection', $connection)
                        ->pluck('payload.connection_type')
                        ->filter()
                        ->unique();

                    return ['name' => $connection, 'type' => $types->count() === 1 ? $types->first() : null];
                }),
            'totalEntries' => $totalEntries,
            'page' => $page,
            'lastPage' => $lastPage,
            'perPage' => self::PER_PAGE,
            'slowThreshold' => $slowThreshold,
        ];
    }
}
