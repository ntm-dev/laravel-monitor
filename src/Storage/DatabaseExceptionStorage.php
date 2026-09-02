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
        // Two narrow passes, not one wide one: `payload` is by far the
        // widest column (an exception's full class/message/file/line/trace),
        // so fetching it for every sampled row here instead of once per
        // group can exhaust PHP's memory limit well before the row cap is
        // even reached — measured: ~3.6k sampled rows averaging ~36KB of
        // payload each (~132MB) blew a 128MB limit on their own, well under
        // the 50k-row cap.
        $groups = $this->query('exception', $since, null, null, $until, $userId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($this->maxSampleRows())
            ->get(['id', 'key', 'subtype', 'user_id', 'created_at'])
            ->groupBy('key');

        if ($groups->isEmpty()) {
            return collect();
        }

        // One payload per group — the latest occurrence (rows arrive
        // newest-first, so each group's first row is it) — not one per
        // sampled row.
        $latestIds = $groups->map(fn (Collection $rows) => $rows->first()->id);

        $payloads = $this->table()
            ->whereIn('id', $latestIds->values()->all())
            ->get(['id', 'payload'])
            ->keyBy('id')
            ->map(static function ($row) {
                $payload = json_decode($row->payload ?? '[]', true) ?: [];

                // Drop everything but what's actually read below — 'trace'
                // alone (Recorders\Exceptions' own captured stack) is
                // usually most of the payload's size, and every group here
                // would otherwise hold one in memory at once.
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
