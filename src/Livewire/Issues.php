<?php

namespace LaravelMonitor\Livewire;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use LaravelMonitor\Contracts\Storage;

class Issues extends Card
{
    /**
     * Areas checked against their own configured threshold for the
     * Performance tab, in the order rows fall back to when max durations tie.
     * type => the monitor_entries `type`; tab => dashboard tab a row links to.
     */
    protected const PERFORMANCE_AREAS = [
        'request' => ['badge' => 'Request', 'tab' => 'requests', 'threshold' => 'request'],
        'job' => ['badge' => 'Job', 'tab' => 'jobs', 'threshold' => 'job'],
        'slow_query' => ['badge' => 'Query', 'tab' => 'queries', 'threshold' => 'query'],
        'outgoing_request' => ['badge' => 'Outgoing', 'tab' => 'outgoing', 'threshold' => 'outgoing_request'],
        'command' => ['badge' => 'Command', 'tab' => 'commands', 'threshold' => 'command'],
    ];

    public const STATUSES = ['open', 'resolved', 'ignored'];

    public string $view = 'exceptions';

    public string $status = 'open';

    public string $search = '';

    public function resolve(string $type, string $key): void
    {
        $this->setStatus($type, $key, 'resolved');
    }

    public function ignore(string $type, string $key): void
    {
        $this->setStatus($type, $key, 'ignored');
    }

    public function reopen(string $type, string $key): void
    {
        $this->setStatus($type, $key, 'open');
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

        $exceptions = $storage->aggregateByKey('exception', $since, null, 50, 'last_seen', $until);

        $latest = $storage->recent('exception', $since, 100, null, null, $until)
            ->groupBy('key')
            ->map(fn ($entries) => $entries->first());

        $exceptions = $exceptions->map(function ($group) use ($latest) {
            $group->latest = $latest->get($group->key)?->payload ?? [];

            return $group;
        });

        $storage->syncIssues('exception', $exceptions->pluck('last_seen', 'key')->filter()->all());
        $exceptions = $this->attachIssueStatus($storage, 'exception', $exceptions);

        $performance = $this->performanceIssues($since, $until);

        foreach ($performance->groupBy('type') as $type => $items) {
            $storage->syncIssues($type, $items->pluck('last_seen', 'key')->filter()->all());
        }

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
                    || stripos($group->latest['message'] ?? '', $needle) !== false)
                ->values();
            $performance = $performance
                ->filter(fn ($item) => stripos($item->label, $needle) !== false)
                ->values();
        }

        return [
            'exceptions' => $exceptions,
            'exceptionCount' => $exceptions->count(),
            'performance' => $performance,
            'performanceCount' => $performance->count(),
            'status' => $status,
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
            $item->status = $found->status ?? 'open';
            $item->first_seen = $found->first_seen ?? $item->last_seen;

            return $item;
        });
    }

    /**
     * Requests, jobs, slow queries and outgoing requests whose max duration
     * breached their own configured threshold, merged into one severity-
     * ordered feed (worst max duration first) — mirroring how Nightwatch
     * surfaces every "over threshold" area as a single Issues list rather
     * than a separate page per area.
     *
     * @return Collection<int, object{type: string, badge: string, label: string, key: string, count: int, max_duration: float}>
     */
    protected function performanceIssues(\DateTimeInterface $since, ?\DateTimeInterface $until): Collection
    {
        $items = collect();

        foreach (self::PERFORMANCE_AREAS as $type => $area) {
            $threshold = (int) config("monitor.thresholds.{$area['threshold']}", 1000);

            $this->storage()
                ->aggregateByKey($type, $since, null, 50, 'max_duration', $until)
                ->filter(fn ($row) => ($row->max_duration ?? 0) >= $threshold)
                ->each(function ($row) use ($items, $type, $area) {
                    $items->push((object) [
                        'type' => $type,
                        'badge' => $area['badge'],
                        'tab' => $area['tab'],
                        'label' => $type === 'job' ? class_basename($row->key) : Str::limit($row->key, 100),
                        'key' => $row->key,
                        'count' => $row->count,
                        'max_duration' => $row->max_duration,
                        'last_seen' => $row->last_seen,
                    ]);
                });
        }

        return $items->sortByDesc('max_duration')->values();
    }
}
