<?php

namespace LaravelMonitor\Livewire;

/**
 * Aggregate view for one mail class (all its sends across the selected
 * period) — analogous to NotificationClassDetail. $key is the mailable/
 * notification FQCN (or, for ad-hoc mail with neither, the subject — see
 * Recorders\Mail's $groupKey), unlike MailDetail where $key is one send's
 * own database id. Its "recent sends" list is where a specific occurrence
 * gets picked: each row links to the request/job attempt timeline that
 * triggered it (mirrors Nightwatch), falling back to MailDetail's own
 * standalone page only when no such correlation exists.
 */
class MailClassDetail extends Card
{
    public string $key = '';

    public function mount(?string $period = null, ?string $from = null, ?string $to = null, ?string $key = null): void
    {
        parent::mount($period, $from, $to);

        $this->key = $key ?? (string) request('key', '');
    }

    protected function view(): string
    {
        return 'monitor::livewire.mail-class-detail';
    }

    protected function data(): array
    {
        $since = $this->since();
        $until = $this->until();
        $storage = $this->storage();
        $buckets = $this->chartBuckets();
        $key = $this->key;

        $stats = $storage->stats('mail', $since, null, $key, $until);

        $entries = $storage->recent('mail', $since, 50, null, $key, $until);

        $rootTypes = $storage->rootTypesFor(
            $entries->pluck('request_id')->filter()->unique()->values()->all()
        );

        $entries = $entries->map(function ($entry) use ($rootTypes) {
            $entry->timeline_url = match ($rootTypes->get($entry->request_id)) {
                'request' => route('monitor.requests.show', $entry->request_id),
                'job' => route('monitor.jobs.attempts.show', $entry->request_id),
                default => null,
            };

            return $entry;
        });

        return [
            'total' => $stats->count,
            'volumeBuckets' => $storage->countsPerBucket('mail', $since, $buckets, null, $key, $until),
            'duration' => $storage->durationStats('mail', $since, $buckets, $key, null, $until),
            'entries' => $entries,
        ];
    }
}
