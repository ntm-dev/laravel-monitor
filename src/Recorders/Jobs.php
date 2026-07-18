<?php

namespace LaravelMonitor\Recorders;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Queue\Events\JobReleasedAfterException;
use Illuminate\Support\Str;

class Jobs extends Recorder
{
    /** @var array<string, float> */
    protected array $startedAt = [];

    public function register(Dispatcher $events): void
    {
        $events->listen(JobQueued::class, [$this, 'recordQueued']);
        $events->listen(JobProcessing::class, [$this, 'recordProcessing']);
        $events->listen(JobProcessed::class, [$this, 'recordProcessed']);
        $events->listen(JobFailed::class, [$this, 'recordFailed']);
        $events->listen(JobReleasedAfterException::class, [$this, 'recordReleased']);
    }

    public function recordQueued(JobQueued $event): void
    {
        $this->monitor->record(
            type: 'job',
            key: is_object($event->job) ? get_class($event->job) : (string) $event->job,
            payload: array_filter([
                'connection' => $event->connectionName,
                'queue' => method_exists($event, 'queue') || property_exists($event, 'queue')
                    ? ($event->queue ?? 'default')
                    : 'default',
                // The driver-assigned queue job id, present for real queue
                // connections (database, redis, sqs, ...) and empty for sync
                // — it's the only thing both this dispatch-time entry and
                // the eventual processed/failed/released entry(ies) share,
                // since retries never re-fire JobQueued. Kept as plain
                // "job_id" (not "correlation_id") since, unlike the mail/
                // notification pairing, it isn't a UUID Monitor minted
                // itself — it's the queue's own identifier for the job.
                'job_id' => $this->jobId($event->id ?? ''),
            ], fn ($value) => $value !== null),
            subtype: 'queued',
        );
    }

    public function recordProcessing(JobProcessing $event): void
    {
        $this->startedAt[$event->job->getJobId() ?: spl_object_hash($event->job)] = microtime(true);

        // Before handle() runs, so everything it triggers (queries, mail,
        // notifications) correlates onto this attempt's own timeline —
        // mirrors RecordTimeline's beginRequest() for HTTP requests.
        $this->monitor->beginJobAttempt();
    }

    public function recordProcessed(JobProcessed $event): void
    {
        $this->monitor->record(
            type: 'job',
            key: $event->job->resolveName(),
            payload: array_filter([
                'connection' => $event->connectionName,
                'queue' => $event->job->getQueue(),
                'job_id' => $this->jobId($event->job->getJobId()),
                'attempts' => $event->job->attempts(),
                'model_count' => $this->monitor->modelCount(),
            ], fn ($value) => $value !== null),
            duration: $this->duration($event->job->getJobId() ?: spl_object_hash($event->job)),
            subtype: 'processed',
        );

        $this->monitor->endJobAttempt();

        // Long-running workers never hit the request lifecycle, so persist now.
        $this->monitor->flush();
    }

    public function recordFailed(JobFailed $event): void
    {
        $this->monitor->record(
            type: 'job',
            key: $event->job->resolveName(),
            payload: array_filter([
                'connection' => $event->connectionName,
                'queue' => $event->job->getQueue(),
                'job_id' => $this->jobId($event->job->getJobId()),
                'attempts' => $event->job->attempts(),
                'model_count' => $this->monitor->modelCount(),
                'exception' => get_class($event->exception),
                'message' => Str::limit($event->exception->getMessage(), 500),
            ], fn ($value) => $value !== null),
            duration: $this->duration($event->job->getJobId() ?: spl_object_hash($event->job)),
            subtype: 'failed',
        );

        $this->monitor->endJobAttempt();

        $this->monitor->flush();
    }

    /**
     * A job released back onto the queue after a caught exception, with
     * attempts remaining — distinct from JobFailed, which only fires once
     * retries are exhausted. Ends this attempt's timeline the same way
     * processed/failed do; the next JobProcessing for the same job starts
     * a fresh one via beginJobAttempt().
     */
    public function recordReleased(JobReleasedAfterException $event): void
    {
        $this->monitor->record(
            type: 'job',
            key: $event->job->resolveName(),
            payload: array_filter([
                'connection' => $event->connectionName,
                'queue' => $event->job->getQueue(),
                'job_id' => $this->jobId($event->job->getJobId()),
                'attempts' => $event->job->attempts(),
                'model_count' => $this->monitor->modelCount(),
                'backoff' => $event->backoff,
            ], fn ($value) => $value !== null),
            duration: $this->duration($event->job->getJobId() ?: spl_object_hash($event->job)),
            subtype: 'released',
        );

        $this->monitor->endJobAttempt();

        $this->monitor->flush();
    }

    protected function duration(string $id): ?float
    {
        $startedAt = $this->startedAt[$id] ?? null;
        unset($this->startedAt[$id]);

        return $startedAt !== null ? round((microtime(true) - $startedAt) * 1000, 2) : null;
    }

    /** Sync jobs never receive a real driver-assigned id — treat '' as absent. */
    protected function jobId(string $id): ?string
    {
        return $id !== '' ? $id : null;
    }
}
