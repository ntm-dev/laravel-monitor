<?php

namespace LaravelMonitor\Recorders;

use BackedEnum;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\Job as QueueJob;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobPopping;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Queue\Events\JobReleasedAfterException;
use Illuminate\Support\Str;
use LaravelMonitor\Http\Controllers\Concerns\NormalizesQueue;
use LaravelMonitor\Support\RecordType;
use ReflectionClass;

use function array_key_exists;
use function get_class;
use function get_object_vars;
use function is_object;
use function is_string;

class Jobs extends Recorder
{
    use NormalizesQueue;

    /** @var array<string, float> */
    protected array $startedAt = [];

    /** @var array<string, float> */
    protected array $poppedAt = [];

    /**
     * JobPopping fires before the job is even deserialized — no id to key
     * this by yet (see resetPeakMemory()'s own docs) — so it's captured
     * bare here and only claimed into {@see $poppedAt}, keyed by this
     * specific job's own id, once recordProcessing() actually has one. Safe
     * because a worker only ever processes one job at a time: JobPopping
     * and the JobProcessing that follows it are never interleaved with
     * another job's own pair.
     */
    protected ?float $lastPoppedAt = null;

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
            type: RecordType::Job,
            key: $this->displayName($event->job),
            payload: array_filter([
                'connection' => $event->connectionName,
                'queue' => $this->normalizeQueue($event->connectionName, $this->resolveQueue($event)),
                // The job's own payload uuid (Illuminate\Queue\Queue::createObjectPayload()),
                // not $event->id (the driver-assigned row/message id) — it's
                // the only thing both this dispatch-time entry and every
                // eventual processed/failed/released entry share, since
                // retries never re-fire JobQueued. $event->id can't be used
                // for that: the database queue driver deletes and
                // re-inserts a job on release() (Illuminate\Queue\DatabaseQueue::release()),
                // so a retried attempt gets a brand-new row id, breaking
                // MergesJobTimelines::jobTrack()'s correlation the moment a
                // job retries on that connection. The payload uuid is part
                // of the same serialized body every attempt reuses, so it
                // survives that re-insert. Falls back to $event->id only for
                // a payload that predates Laravel's uuid field. Kept as
                // plain "job_id" (not "correlation_id") since, unlike the
                // mail/notification pairing, this field already existed
                // under that name before the uuid switch.
                'job_id' => $this->jobId($event->payload()['uuid'] ?? $event->id ?? ''),
            ], fn ($value) => $value !== null),
            subtype: 'queued',
            userId: $this->monitor->lazyCurrentUserId(),
        );
    }

    /**
     * Fires before the job is even popped off the queue, ahead of
     * JobProcessing — scoping the peak reported by
     * recordProcessed()/recordFailed()/recordReleased() to this one job
     * (see Recorder::resetPeakMemoryUsage()).
     */
    public function resetPeakMemory(JobPopping $event): void
    {
        $this->resetPeakMemoryUsage();

        $this->lastPoppedAt = microtime(true);
    }

    public function recordProcessing(JobProcessing $event): void
    {
        $id = $event->job->getJobId() ?: spl_object_hash($event->job);

        $this->startedAt[$id] = microtime(true);

        if ($this->lastPoppedAt !== null) {
            $this->poppedAt[$id] = $this->lastPoppedAt;
            $this->lastPoppedAt = null;
        }

        // Before handle() runs, so everything it triggers (queries, mail,
        // notifications) correlates onto this attempt's own timeline —
        // mirrors the booted-callback beginRequest() for HTTP requests.
        $this->monitor->beginJobAttempt();
    }

    public function recordProcessed(JobProcessed $event): void
    {
        $id = $event->job->getJobId() ?: spl_object_hash($event->job);

        $this->monitor->record(
            type: RecordType::Job,
            key: $event->job->resolveName(),
            payload: array_filter([
                'connection' => $event->connectionName,
                'queue' => $event->job->getQueue(),
                'job_id' => $this->correlationId($event->job),
                'attempts' => $event->job->attempts(),
                'model_count' => $this->monitor->modelCount(),
                'server' => gethostname() ?: null,
                'peak_memory' => memory_get_peak_usage(true),
                // Recorded here rather than reconstructed later from
                // created_at - duration (see MergesJobTimelines::jobTrack(),
                // which prefers this when present) — this is the exact
                // moment JobProcessing fired, not an approximation of it.
                'started_at' => $this->startedAt[$id] ?? null,
                // The moment this attempt's own JobPopping fired, before
                // the job was even deserialized — see resetPeakMemory()/
                // recordProcessing()'s own docs on how this gets attributed
                // to this specific id. Absent for a retry that never went
                // back through the queue at all (a job released and picked
                // straight back up in-process, if a driver ever does that).
                'popped_at' => $this->poppedAt[$id] ?? null,
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
            type: RecordType::Job,
            key: $event->job->resolveName(),
            payload: array_filter([
                'connection' => $event->connectionName,
                'queue' => $event->job->getQueue(),
                'job_id' => $this->correlationId($event->job),
                'attempts' => $event->job->attempts(),
                'model_count' => $this->monitor->modelCount(),
                'server' => gethostname() ?: null,
                'peak_memory' => memory_get_peak_usage(true),
                'exception' => get_class($event->exception),
                'message' => Str::limit($event->exception->getMessage(), 500),
                // See recordProcessed()'s own 'started_at'/'popped_at' for
                // why these are recorded here instead of reconstructed later.
                'started_at' => $this->startedAt[$id] ?? null,
                'popped_at' => $this->poppedAt[$id] ?? null,
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
            type: RecordType::Job,
            key: $event->job->resolveName(),
            payload: array_filter([
                'connection' => $event->connectionName,
                'queue' => $event->job->getQueue(),
                'job_id' => $this->correlationId($event->job),
                'attempts' => $event->job->attempts(),
                'model_count' => $this->monitor->modelCount(),
                'server' => gethostname() ?: null,
                'peak_memory' => memory_get_peak_usage(true),
                // backoff only exists on this event from Laravel 12 onward (#58414);
                // `??` avoids an "Undefined property" error under E_ALL on older versions.
                'backoff' => $event->backoff ?? null,
                // See recordProcessed()'s own 'started_at'/'popped_at' for
                // why these are recorded here instead of reconstructed later.
                'started_at' => $this->startedAt[$id] ?? null,
                'popped_at' => $this->poppedAt[$id] ?? null,
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
        unset($this->startedAt[$id], $this->poppedAt[$id]);

        // round(x, 3): both operands are ~1.7-billion-magnitude Unix epoch
        // floats, so subtracting them is a floating-point catastrophic
        // cancellation — see Monitor::elapsedMsPrecise()'s own docs. 3
        // decimals matches microtime()'s own microsecond resolution.
        return $startedAt !== null ? round((microtime(true) - $startedAt) * 1000, 3) : null;
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

    /** Treats '' as absent — SyncJob::getJobId() always returns '', the fallback correlationId() reaches when a payload has no uuid. */
    protected function jobId(string $id): ?string
    {
        return $id !== '' ? $id : null;
    }

    /**
     * This attempt's own correlation id for the 'job_id' payload field (see
     * recordQueued()'s own comment on that field) — its payload uuid when
     * present, since that's stable across every retry of the same dispatch,
     * unlike getJobId() (some drivers, e.g. the database queue, reassign
     * that on every attempt — see recordQueued()). Falls back to getJobId()
     * only for a payload that predates Laravel's uuid field.
     */
    protected function correlationId(QueueJob $job): ?string
    {
        return $this->jobId($job->uuid() ?? $job->getJobId());
    }

    private function resolveQueue(JobQueued $event): string
    {
        /**
         * This property has not always existed, and its type has not always
         * been correct either. It was missing, added, removed, and re-added
         * through time — `property_exists` avoids an "Undefined property"
         * error under E_ALL on versions where it's absent, and the docblock
         * forces the type on versions where it is.
         *
         * @see https://github.com/laravel/framework/pull/55058
         *
         * @var string|null $queue
         */
        $queue = property_exists($event, 'queue') ? $event->queue : null;

        if ($queue !== null) {
            return $this->parseQueue($queue);
        }

        if (is_object($event->job)) {
            // get_object_vars(), called from outside the job's own class,
            // only returns its *public* properties — unlike property_exists(),
            // which doesn't care about visibility and would let us try to
            // read a protected $queue (e.g. Illuminate\Queue\Jobs\Job's own)
            // and fatal with "Cannot access protected property".
            $jobVars = get_object_vars($event->job);

            if (array_key_exists('queue', $jobVars) && $jobVars['queue'] !== null) {
                return $jobVars['queue'];
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
