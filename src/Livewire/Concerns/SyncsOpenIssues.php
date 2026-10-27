<?php

namespace LaravelMonitor\Livewire\Concerns;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use LaravelMonitor\Contracts\AggregateStorage;
use LaravelMonitor\Contracts\IssueStorage;
use LaravelMonitor\Livewire\Issues;

/**
 * Writes new/recurring issues via syncIssues(). Runs only from the Issues
 * page and `monitor:prune`, so openIssueCount() advances only then.
 */
trait SyncsOpenIssues
{
    /**
     * Per-type aggregateByKey() limit; groups past it stop being synced.
     * A method because trait constants need PHP 8.2 (floor is 8.1).
     */
    protected function groupLimit(): int
    {
        return 500;
    }

    /**
     * Types whose aggregate hit groupLimit() this round; syncOpenIssues()
     * skips deleteMissingIssues() for them since keys past the cap look absent.
     *
     * @var list<string>
     */
    protected array $truncatedPerformanceTypes = [];

    /**
     * Issues ignore the period switcher and look back 5 years, matching
     * IssueController.
     */
    protected function since(): CarbonImmutable
    {
        return CarbonImmutable::now()->subYears(5);
    }

    protected function until(): ?CarbonImmutable
    {
        return null;
    }

    /**
     * Sync every exception group and performance-threshold breach in range
     * into the issues table.
     *
     * @return array{0: Collection, 1: Collection} [exceptions, performance]
     */
    protected function syncOpenIssues(AggregateStorage $aggregateStorage, IssueStorage $issueStorage, DateTimeInterface $since, ?DateTimeInterface $until): array
    {
        $exceptions = $aggregateStorage->aggregateByKey('exception', $since, null, $this->groupLimit(), 'last_seen', $until);
        $issueStorage->syncIssues('exception', $exceptions->pluck('last_seen', 'key')->filter()->all());

        if ($exceptions->count() < $this->groupLimit()) {
            $issueStorage->deleteMissingIssues('exception', $exceptions->pluck('key')->all());
        }

        $performance = $this->performanceIssues($aggregateStorage, $since, $until);
        $grouped = $performance->groupBy('type');

        // Every area, even with no rows this round, so its stale issues still get deleted.
        foreach (Issues::PERFORMANCE_AREAS as $type => $area) {
            $items = $grouped->get($type, collect());
            $issueStorage->syncIssues($type, $items->pluck('last_seen', 'key')->filter()->all());

            if (! in_array($type, $this->truncatedPerformanceTypes, true)) {
                $issueStorage->deleteMissingIssues($type, $items->pluck('key')->all());
            }
        }

        return [$exceptions, $performance];
    }

    /**
     * Keys of every PERFORMANCE_AREAS type whose max duration breached its
     * configured threshold, worst first.
     *
     * @return Collection<int, object{type: string, badge: string, tab: string, label: string, key: string, count: int, max_duration: float, last_seen: CarbonImmutable|null}>
     */
    protected function performanceIssues(AggregateStorage $aggregateStorage, DateTimeInterface $since, ?DateTimeInterface $until): Collection
    {
        $items = collect();
        $this->truncatedPerformanceTypes = [];

        foreach (Issues::PERFORMANCE_AREAS as $type => $area) {
            $threshold = (int) config("monitor.thresholds.{$area['threshold']}", 1000);

            $raw = $aggregateStorage->aggregateByKey($type, $since, null, $this->groupLimit(), 'max_duration', $until);

            if ($raw->count() >= $this->groupLimit()) {
                $this->truncatedPerformanceTypes[] = $type;
            }

            $raw
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
