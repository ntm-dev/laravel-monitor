<?php

namespace LaravelMonitor\Livewire\Concerns;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use LaravelMonitor\Contracts\Storage;
use LaravelMonitor\Livewire\Issues;

/**
 * Shared by Issues (which renders the synced results) and OpenIssueBadge
 * (which only needs the side effect): syncIssues() — the only thing that
 * writes a new/recurring issue into monitor_issues — has no trigger of its
 * own, so without every "open issues" surface calling this, openIssueCount()
 * only ever advances whenever someone happens to have the Issues page open.
 */
trait SyncsOpenIssues
{
    /**
     * aggregateByKey()'s own $limit param, applied per type. Issues has no
     * pagination of its own — anything past this cap silently stops getting
     * synced (frozen last_seen, invisible on the page) the moment total
     * distinct groups for a type exceeds it, even though it's still
     * genuinely open. 500 is a generous ceiling against the small default
     * page-size limits (10-50) every other card in this package uses.
     *
     * A method, not a trait constant — traits can't declare constants until
     * PHP 8.2, and this package's floor is 8.1.
     */
    protected function groupLimit(): int
    {
        return 500;
    }

    /**
     * Issues is a persistent open/resolved/ignored tracker, not a
     * time-windowed report — it has no period switcher (see header.blade.php)
     * so it always looks back far enough to surface every still-open issue,
     * same convention IssueController's detail page already uses.
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
     * Discovers every exception group / performance-threshold breach
     * currently in range and syncs it into monitor_issues (new row on first
     * sight, bumped last_seen otherwise).
     *
     * @return array{0: Collection, 1: Collection} [exceptions, performance]
     */
    protected function syncOpenIssues(Storage $storage, DateTimeInterface $since, ?DateTimeInterface $until): array
    {
        $exceptions = $storage->aggregateByKey('exception', $since, null, $this->groupLimit(), 'last_seen', $until);
        $storage->syncIssues('exception', $exceptions->pluck('last_seen', 'key')->filter()->all());

        $performance = $this->performanceIssues($storage, $since, $until);

        foreach ($performance->groupBy('type') as $type => $items) {
            $storage->syncIssues($type, $items->pluck('last_seen', 'key')->filter()->all());
        }

        return [$exceptions, $performance];
    }

    /**
     * Requests, jobs, slow queries and outgoing requests whose max duration
     * breached their own configured threshold, merged into one severity-
     * ordered feed (worst max duration first), surfacing every "over
     * threshold" area as a single Issues list rather than a separate page
     * per area.
     *
     * @return Collection<int, object{type: string, badge: string, label: string, key: string, count: int, max_duration: float}>
     */
    protected function performanceIssues(Storage $storage, DateTimeInterface $since, ?DateTimeInterface $until): Collection
    {
        $items = collect();

        foreach (Issues::PERFORMANCE_AREAS as $type => $area) {
            $threshold = (int) config("monitor.thresholds.{$area['threshold']}", 1000);

            $storage
                ->aggregateByKey($type, $since, null, $this->groupLimit(), 'max_duration', $until)
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
