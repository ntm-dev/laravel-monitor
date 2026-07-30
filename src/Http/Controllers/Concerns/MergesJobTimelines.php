<?php

namespace LaravelMonitor\Http\Controllers\Concerns;

use DateTimeInterface;
use Illuminate\Support\Collection;

/**
 * Shared by RequestDetailController/JobAttemptController/CommandRunController/
 * ScheduleRunController: resolves the outcome (and its own children) of every
 * job a root dispatched, so Support\Timeline::build() can splice each one's
 * own sub-timeline in — matches Nightwatch's single merged trace view
 * instead of a dead-end "queued" placeholder (see Support\Timeline's own
 * docs for why this is wall-clock, not proportionally, positioned). Relies
 * on the consuming controller's own constructor-promoted `$this->storage`
 * (every one of the four already has it).
 */
trait MergesJobTimelines
{
    protected function jobExecutionsFor(Collection $children, DateTimeInterface $since): Collection
    {
        $jobIds = $children
            ->filter(fn (object $row) => $row->type === 'job' && $row->subtype === 'queued')
            ->map(fn (object $row) => $row->payload['job_id'] ?? null)
            ->filter()
            ->values()
            ->all();

        if ($jobIds === []) {
            return collect();
        }

        return $this->storage->jobExecutionsByJobId($jobIds, $since);
    }
}
