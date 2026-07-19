<?php

namespace LaravelMonitor\Livewire;

use LaravelMonitor\Livewire\Concerns\BuildsExceptionDetail;
use LaravelMonitor\Livewire\Concerns\ResolvesUserNames;
use LaravelMonitor\Support\Format;

class ExceptionDetail extends Card
{
    use BuildsExceptionDetail;
    use ResolvesUserNames;

    public string $key = '';

    public function mount(?string $period = null, ?string $from = null, ?string $to = null, ?string $key = null): void
    {
        parent::mount($period, $from, $to);

        $this->key = $key ?? (string) request('key', '');
    }

    protected function view(): string
    {
        return 'monitor::livewire.exception-detail';
    }

    protected function data(): array
    {
        $since = $this->since();
        $until = $this->until();
        $storage = $this->storage();
        $buckets = self::CHART_BUCKETS;
        $key = $this->key;

        $group = $storage->exceptionGroups($since, $until)->firstWhere('key', $key);
        $occurrences = $storage->recent('exception', $since, 200, null, $key, $until);
        $latest = $occurrences->first();
        $payload = $latest->payload ?? [];

        $names = $this->resolveNames(
            $occurrences->pluck('user_id')->filter(fn ($id) => $id !== null)->unique()->all()
        );

        $servers = $occurrences->pluck('payload.server')->filter()->unique()->values();
        $handled = ($group?->unhandled ?? 0) === 0;
        $tz = Format::timezone();

        $lastSeen = $group?->last_seen ?? $latest?->created_at;
        $firstSeen = $storage->firstSeen('exception', $key) ?? $group?->first_seen;
        $phpVersion = $payload['php_version'] ?? null;
        $laravelVersion = $payload['laravel_version'] ?? null;
        $occurrencesCount = $group?->count ?? $storage->stats('exception', $since, null, $key, $until)->count;

        return [
            'key' => $key,
            'exists' => $latest !== null,
            'class' => $payload['class'] ?? $key,
            'message' => $payload['message'] ?? null,
            'file' => $payload['file'] ?? null,
            'line' => $payload['line'] ?? null,
            'handled' => $handled,
            'tz' => $tz,
            'phpVersion' => $phpVersion,
            'laravelVersion' => $laravelVersion,
            'frameGroups' => $this->frameGroups($payload['frames'] ?? []),
            'markdown' => $this->markdown($payload, $handled),
            'summary' => $this->summary($lastSeen, $firstSeen, $phpVersion, $laravelVersion, (int) ($group?->users ?? 0), $occurrencesCount, $servers, $tz),
            'occurrencesCount' => $occurrencesCount,
            'handledCount' => $group?->handled ?? 0,
            'unhandledCount' => $group?->unhandled ?? 0,
            // Timeline for this exception
            'handledBuckets' => $storage->countsPerBucket('exception', $since, $buckets, 'handled', $key, $until),
            'unhandledBuckets' => $storage->countsPerBucket('exception', $since, $buckets, 'unhandled', $key, $until),
            // Occurrences table
            'occurrences' => $occurrences->take(50)->map(fn ($row) => (object) [
                'date' => Format::datetime($row->created_at),
                'server' => $row->payload['server'] ?? null,
                'message' => $row->payload['message'] ?? null,
                'user' => $row->user_id !== null ? ($names[$row->user_id] ?? "User #{$row->user_id}") : null,
            ]),
        ];
    }
}
