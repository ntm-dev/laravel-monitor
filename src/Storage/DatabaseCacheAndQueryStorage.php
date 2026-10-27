<?php

namespace LaravelMonitor\Storage;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use LaravelMonitor\Contracts\CacheAndQueryStorage;
use LaravelMonitor\Storage\Concerns\BuildsQueries;
use LaravelMonitor\Support\QueryConnection;

class DatabaseCacheAndQueryStorage implements CacheAndQueryStorage
{
    use BuildsQueries;

    public function cacheKeyStats(DateTimeInterface $since, ?DateTimeInterface $until = null): Collection
    {
        return $this->cacheRemember(__FUNCTION__, func_get_args(), $until, fn () => $this->cacheKeyStatsUncached($since, $until));
    }

    protected function cacheKeyStatsUncached(DateTimeInterface $since, ?DateTimeInterface $until): Collection
    {
        // GROUP BY over a LIMITed subquery: a direct GROUP BY may make MySQL walk the
        // [type, key] index and look up every row to check created_at. Ordered by
        // created_at (index-walkable), not id, which would force a full sort before the LIMIT.
        $sample = $this->table()
            ->where('type', 'cache')
            ->where('created_at', '>=', $since)
            ->when($until !== null, fn (Builder $q) => $q->where('created_at', '<=', $until))
            ->select(['key', 'subtype'])
            ->orderByDesc('created_at')
            ->limit($this->maxSampleRows());

        return $this->table()
            ->fromSub($sample, 't')
            ->select('key')
            ->selectRaw("sum(case when subtype = 'hit' then 1 else 0 end) as hits")
            ->selectRaw("sum(case when subtype = 'miss' then 1 else 0 end) as misses")
            ->selectRaw("sum(case when subtype = 'write' then 1 else 0 end) as writes")
            ->selectRaw("sum(case when subtype = 'forget' then 1 else 0 end) as deletes")
            ->selectRaw("sum(case when subtype in ('write_failed', 'forget_failed') then 1 else 0 end) as failures")
            ->selectRaw('count(*) as aggregate_count')
            ->groupBy('key')
            ->get()
            ->map(function ($row) {
                $hits = (int) $row->hits;
                $misses = (int) $row->misses;

                return (object) [
                    'key' => $row->key,
                    'hit_ratio' => ($hits + $misses) > 0 ? round($hits / ($hits + $misses) * 100) : null,
                    'hits' => $hits,
                    'misses' => $misses,
                    'writes' => (int) $row->writes,
                    'deletes' => (int) $row->deletes,
                    'failures' => (int) $row->failures,
                    'total' => (int) $row->aggregate_count,
                ];
            });
    }

    public function queryStats(DateTimeInterface $since, ?DateTimeInterface $until = null): Collection
    {
        return $this->cacheRemember(__FUNCTION__, func_get_args(), $until, fn () => $this->queryStatsUncached($since, $until));
    }

    protected function queryStatsUncached(DateTimeInterface $since, ?DateTimeInterface $until): Collection
    {
        $rows = $this->table()
            ->where('type', 'query')
            ->where('created_at', '>=', $since)
            ->when($until !== null, fn (Builder $q) => $q->where('created_at', '<=', $until))
            // `id` tiebreak: see DatabaseAggregateStorage::sampleDurationsAcrossBuckets().
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($this->maxSampleRows())
            // `subtype` packs connection + role (see QueryConnection), so no payload needed.
            ->get(['key', 'subtype', 'duration', 'created_at']);

        // Plain arrays, not a Collection per group: too slow at the sample cap.
        $groups = [];

        foreach ($rows as $row) {
            [$connection, $connectionType] = QueryConnection::parse($row->subtype);
            $connection ??= 'default';
            $groupKey = $row->key.'@@'.$connection;

            $group = &$groups[$groupKey];
            // Rows arrive newest-first, so the first row seen is last_seen.
            $group ??= ['key' => (string) $row->key, 'connection' => $connection, 'calls' => 0, 'durations' => [], 'connectionTypes' => [], 'last_seen' => $row->created_at];

            $group['calls']++;

            if ($row->duration !== null) {
                $group['durations'][] = (float) $row->duration;
            }

            if ($connectionType !== null) {
                $group['connectionTypes'][$connectionType] = true;
            }
            unset($group);
        }

        $result = [];

        foreach ($groups as $group) {
            $durations = $group['durations'];

            ksort($group['connectionTypes']);

            $result[] = (object) [
                'key' => $group['key'],
                'connection' => $group['connection'],
                // All roles seen: one statement can hit both sides of a read/write split.
                'connection_types' => array_keys($group['connectionTypes']),
                'calls' => $group['calls'],
                'total' => round(array_sum($durations), 2),
                'avg' => $durations === [] ? null : round(array_sum($durations) / count($durations), 2),
                'p95' => $this->percentile($durations, 0.95),
                'last_seen' => CarbonImmutable::parse($group['last_seen']),
            ];
        }

        return collect($result);
    }
}
