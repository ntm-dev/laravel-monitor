<?php

namespace LaravelMonitor\Storage;

use DateTimeInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use LaravelMonitor\Contracts\CacheAndQueryStorage;
use LaravelMonitor\Storage\Concerns\BuildsQueries;

class DatabaseCacheAndQueryStorage implements CacheAndQueryStorage
{
    use BuildsQueries;

    public function cacheKeyStats(DateTimeInterface $since, ?DateTimeInterface $until = null): Collection
    {
        // GROUP BY key over the raw table, not the sampled subquery below:
        // MySQL sees an index it can walk in key order (idx_type_key) and
        // prefers that over a covering index that's actually filtered by
        // created_at first, to avoid a sort step for the GROUP BY — cheap
        // when the date filter matches most of the table, ruinous when it
        // doesn't, since every matching row then needs a lookup just to
        // check created_at. Capping the input via a LIMITed subquery keeps
        // the group-by's input bounded regardless of which index MySQL
        // picks for it. Ordered by created_at, not id: id isn't part of any
        // index here, so sorting by it forces a full sort of every matching
        // row before the LIMIT can apply — created_at is the index's
        // leading range column, so MySQL can walk it backwards and stop at
        // the limit instead (measured 85x faster). Ties on the same
        // created_at second come back in whatever order the storage engine
        // hands them over, which is fine — nothing here depends on their
        // relative order.
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
        $rows = $this->table()
            ->where('type', 'query')
            ->where('created_at', '>=', $since)
            ->when($until !== null, fn (Builder $q) => $q->where('created_at', '<=', $until))
            // created_at, not id, as the primary sort — see cacheKeyStats()
            // for why — but this sample's duration values feed avg/p95
            // directly (unlike cacheKeyStats' count-only aggregates), so a
            // tied created_at second needs a deterministic secondary sort.
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($this->maxSampleRows())
            ->get(['key', 'duration', 'payload']);

        // Single foreach pass with plain arrays — building/re-collecting a
        // Collection per group was measurably slower at the sample cap.
        $groups = [];

        foreach ($rows as $row) {
            $payload = json_decode($row->payload ?? '[]', true) ?: [];
            $connection = $payload['connection'] ?? 'default';
            $groupKey = $row->key.'@@'.$connection;

            $group = &$groups[$groupKey];
            $group ??= ['key' => (string) $row->key, 'connection' => $connection, 'calls' => 0, 'durations' => [], 'connectionTypes' => []];

            $group['calls']++;

            if ($row->duration !== null) {
                $group['durations'][] = (float) $row->duration;
            }

            if (isset($payload['connection_type'])) {
                $group['connectionTypes'][$payload['connection_type']] = true;
            }
            unset($group);
        }

        $result = [];

        foreach ($groups as $group) {
            $durations = $group['durations'];

            $result[] = (object) [
                'key' => $group['key'],
                'connection' => $group['connection'],
                // Only shown when every sampled call agreed on one role —
                // a connection name can carry more than one role across
                // calls (e.g. a sticky read/write split alternating between
                // the two), and showing one at random would be exactly the
                // guesswork this column replaced Sql::isWrite() to avoid.
                'connection_type' => count($group['connectionTypes']) === 1 ? array_key_first($group['connectionTypes']) : null,
                'calls' => $group['calls'],
                'total' => round(array_sum($durations), 2),
                'avg' => $durations === [] ? null : round(array_sum($durations) / count($durations), 2),
                'p95' => $this->percentile($durations, 0.95),
            ];
        }

        return collect($result);
    }
}
