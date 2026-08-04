<?php

namespace LaravelMonitor\Recorders;

use BackedEnum;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobPopping;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Queue\Events\JobReleasedAfterException;
use Illuminate\Support\Str;
use LaravelMonitor\Http\Controllers\Concerns\NormalizesQueue;
use ReflectionClass;

use function is_object;
use function is_string;
use function get_class;

class Jobs extends Recorder
{
    use NormalizesQueue;

    /** @var array<string, float> */
    protected array $startedAt = [];

    public function register(Dispatcher $events): void
    {
        $events->listen(JobQueued::class, [$this, 'recordQueued']);
        $events->listen(JobPopping::class, [$this, 'resetPeakMemory']);
        $events->listen(JobProcessing::class, [$this, 'recordProcessing']);
        $events->listen(JobProcessed::class, [$this, 'recordProcessed']);
        $events->listen(JobFailed::class, [$this, 'recordFailed']);
        $events->listen(JobReleasedAfterException::class, [$this, 'recordReleased']);
    }

    public function recordQueued(JobQueued $event): void
    {
        $this->monitor->record(
            type: 'job',
            key: $this->displayName($event->job),
            payload: array_filter([
                'connection' => $event->connectionName,
                'queue' => $this->normalizeQueue($event->connectionName, $this->resolveQueue($event)),
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

    /**
     * Fires before the job is even popped off the queue, ahead of
     * JobProcessing — a long-running worker never restarts its PHP process
     * between jobs, so without this reset memory_get_peak_usage() in
     * recordProcessed()/recordFailed()/recordReleased() would report the
     * cumulative peak across every job that process has ever run, not this
     * one's own.
     */
    public function resetPeakMemory(JobPopping $event): void
    {
        memory_reset_peak_usage();
    }

    public function recordProcessing(JobProcessing $event): void
    {
        $this->startedAt[$event->job->getJobId() ?: spl_object_hash($event->job)] = microtime(true);

        // Before handle() runs, so everything it triggers (queries, mail,
        // notifications) correlates onto this attempt's own timeline —
        // mirrors the booted-callback beginRequest() for HTTP requests.
        $this->monitor->beginJobAttempt();
    }

    public function recordProcessed(JobProcessed $event): void
    {
        $id = $event->job->getJobId() ?: spl_object_hash($event->job);

        $this->monitor->record(
            type: 'job',
            key: $event->job->resolveName(),
            payload: array_filter([
                'connection' => $event->connectionName,
                'queue' => $event->job->getQueue(),
                'job_id' => $this->jobId($event->job->getJobId()),
                'attempts' => $event->job->attempts(),
                'model_count' => $this->monitor->modelCount(),
                'server' => gethostname() ?: null,
                'peak_memory' => memory_get_peak_usage(true),
                // Recorded here rather than reconstructed later from
                // created_at - duration (see MergesJobTimelines::jobTrack(),
                // which prefers this when present) — this is the exact
                // moment JobProcessing fired, not an approximation of it.
                'started_at' => $this->startedAt[$id] ?? null,
            ], fn ($value) => $value !== null),
            duration: $this->duration($id),
            subtype: 'processed',
        );

        $this->monitor->endJobAttempt();

        // Long-running workers never hit the request lifecycle, so persist now.
        $this->monitor->flush();
    }

    public function recordFailed(JobFailed $event): void
    {
        $id = $event->job->getJobId() ?: spl_object_hash($event->job);

        $this->monitor->record(
            type: 'job',
            key: $event->job->resolveName(),
            payload: array_filter([
                'connection' => $event->connectionName,
                'queue' => $event->job->getQueue(),
                'job_id' => $this->jobId($event->job->getJobId()),
                'attempts' => $event->job->attempts(),
                'model_count' => $this->monitor->modelCount(),
                'server' => gethostname() ?: null,
                'peak_memory' => memory_get_peak_usage(true),
                'exception' => get_class($event->exception),
                'message' => Str::limit($event->exception->getMessage(), 500),
                // See recordProcessed()'s own 'started_at' for why this is
                // recorded here instead of reconstructed later.
                'started_at' => $this->startedAt[$id] ?? null,
            ], fn ($value) => $value !== null),
            duration: $this->duration($id),
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
        $id = $event->job->getJobId() ?: spl_object_hash($event->job);

        $this->monitor->record(
            type: 'job',
            key: $event->job->resolveName(),
            payload: array_filter([
                'connection' => $event->connectionName,
                'queue' => $event->job->getQueue(),
                'job_id' => $this->jobId($event->job->getJobId()),
                'attempts' => $event->job->attempts(),
                'model_count' => $this->monitor->modelCount(),
                'server' => gethostname() ?: null,
                'peak_memory' => memory_get_peak_usage(true),
                // backoff only exists on this event from Laravel 12 onward (#58414);
                // `??` avoids an "Undefined property" error under E_ALL on older versions.
                'backoff' => $event->backoff ?? null,
                // See recordProcessed()'s own 'started_at' for why this is
                // recorded here instead of reconstructed later.
                'started_at' => $this->startedAt[$id] ?? null,
            ], fn ($value) => $value !== null),
            duration: $this->duration($id),
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

    /**
     * JobQueued::$job is the raw, as-dispatched job — for a job pushed via
     * Mail::queue()/Notification::send(..., queue), that's Laravel's own
     * Illuminate\Mail\SendQueuedMailable/SendQueuedNotifications wrapper,
     * not the Mailable/Notification itself, so a plain get_class() here
     * would record every queued mail as an indistinguishable "job" of that
     * one wrapper class. Both of those (and any other job customizing
     * displayName()) already resolve to the *wrapped* class instead once
     * processed (see Illuminate\Queue\Jobs\Job::resolveName(), which
     * recordProcessed()/recordFailed()/recordReleased() rely on via
     * $event->job->resolveName()) — mirroring that same resolution here
     * keeps this dispatch-time entry's own key consistent with its
     * eventual outcome's.
     */
    protected function displayName(mixed $job): string
    {
        if (! is_object($job)) {
            return (string) $job;
        }

        return method_exists($job, 'displayName') ? $job->displayName() : get_class($job);
    }

    /** Sync jobs never receive a real driver-assigned id — treat '' as absent. */
    protected function jobId(string $id): ?string
    {
        return $id !== '' ? $id : null;
    }

    private function resolveQueue(JobQueued $event): string
    {
        /**
         * This property has not always had the correct type. It was missing,
         * added, removed, and re-added through time. We will force the type
         * here so we know what we are dealing with across all versions.
         *
         * @see https://github.com/laravel/framework/pull/55058
         *
         * @var string|null $queue
         */
        $queue = $event->queue;

        if ($queue !== null) {
            return $this->parseQueue($queue);
        }

        if (is_object($event->job)) {
            if (property_exists($event->job, 'queue') && $event->job->queue !== null) {
                return $event->job->queue;
            }

            if ($event->job instanceof CallQueuedListener) {
                $queue = $this->resolveQueuedListenerQueue($event->job);
            }
        }

        return $queue ?? config("queue.connections.{$event->connectionName}.queue", '');
    }

    private function parseQueue(string|BackedEnum $queue): string
    {
        return is_string($queue) ? $queue : (string) $queue->value;
    }

    private function resolveQueuedListenerQueue(CallQueuedListener $listener): ?string
    {
        $reflectionJob = (new ReflectionClass($listener->class))->newInstanceWithoutConstructor();

        if (method_exists($reflectionJob, 'viaQueue')) {
            return $reflectionJob->viaQueue($listener->data[0] ?? null);
        }

        return $reflectionJob->queue ?? null;
    }
}
