<?php

namespace LaravelMonitor\Storage;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Collection;
use LaravelMonitor\Contracts\ExceptionStorage;
use LaravelMonitor\Storage\Concerns\BuildsQueries;

class DatabaseExceptionStorage implements ExceptionStorage
{
    use BuildsQueries;

    public function exceptionGroups(
        DateTimeInterface $since,
        ?DateTimeInterface $until = null,
        int|string|null $userId = null,
    ): Collection {
        return $this->cacheRemember(
            __FUNCTION__,
            func_get_args(),
            $until,
            fn () => $this->exceptionGroupsUncached($since, $until, $userId),
        );
    }

    protected function exceptionGroupsUncached(
        DateTimeInterface $since,
        ?DateTimeInterface $until,
        int|string|null $userId,
    ): Collection {
        // Sample without `payload` (it holds the trace and can exhaust memory),
        // then fetch it only for each group's latest row.
        $groups = $this->query('exception', $since, null, null, $until, $userId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($this->maxSampleRows())
            ->get(['id', 'key', 'subtype', 'user_id', 'created_at'])
            ->groupBy('key');

        if ($groups->isEmpty()) {
            return collect();
        }

        // Rows are newest-first, so each group's first row is its latest.
        $latestIds = $groups->map(fn (Collection $rows) => $rows->first()->id);

        $payloads = $this->table()
            ->whereIn('id', $latestIds->values()->all())
            ->get(['id', 'payload'])
            ->keyBy('id')
            ->map(static function ($row) {
                $payload = json_decode($row->payload ?? '[]', true) ?: [];

                // Keep only the fields read below; drop the large 'trace'.
                return array_intersect_key($payload, ['class' => true, 'message' => true, 'file' => true, 'line' => true]);
            });

        return $groups->map(function (Collection $rows, string $key) use ($latestIds, $payloads) {
            $latest = $payloads->get($latestIds->get($key), []);

            return (object) [
                'key' => $key,
                'class' => $latest['class'] ?? $key,
                'message' => $latest['message'] ?? null,
                'file' => $latest['file'] ?? null,
                'line' => $latest['line'] ?? null,
                'count' => $rows->count(),
                'handled' => $rows->where('subtype', 'handled')->count(),
                'unhandled' => $rows->where('subtype', 'unhandled')->count(),
                'users' => $rows->pluck('user_id')->filter(fn ($id) => $id !== null)->unique()->count(),
                'last_seen' => CarbonImmutable::parse($rows->max('created_at')),
                'first_seen' => CarbonImmutable::parse($rows->min('created_at')),
            ];
        })
            ->values();
    }
}
