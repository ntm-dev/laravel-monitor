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
use LaravelMonitor\LazyValue;
use LaravelMonitor\Support\Host;
use LaravelMonitor\Support\RecordType;
use LaravelMonitor\Support\Trace;
use ReflectionClass;

use function array_key_exists;
use function get_class;
use function get_object_vars;
use function is_object;
use function is_string;

class Jobs extends Recorder
{
    use NormalizesQueue;

    /**
     * Dispatch-time placeholder subtype. A job that queues further jobs
     * records these under its own request_id, alongside its own outcome row.
     */
    public const DISPATCH = 'queued';

    /**
     * In-flight attempt, recorded before handle() under the same request_id
     * as its eventual outcome — read paths expecting one outcome row must
     * exclude it (e.g. DatabaseTimelineStorage::rootQuery(), Monitor::startOffsetFor()).
     */
    public const PROCESSING = 'processing';

    /** @var array<string, float> */
    protected array $startedAt = [];

    /** @var array<string, float> */
    protected array $poppedAt = [];

    /**
     * JobPopping has no job id yet; recordProcessing() moves this into
     * $poppedAt. Safe since a worker runs one job at a time.
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
        $trace = ($this->config['details']['trace'] ?? false) ? Trace::capture() : null;

        $this->monitor->record(
            type: RecordType::Job,
            key: $this->displayName($event->job),
            payload: array_filter([
                'connection' => $event->connectionName,
                'queue' => $this->normalizeQueue($event->connectionName, $this->resolveQueue($event)),
                // Payload uuid, shared by every attempt; $event->id changes when
                // the database driver re-inserts a released job. Falls back to
                // $event->id for payloads without a uuid.
                'job_id' => $this->jobId($event->payload()['uuid'] ?? $event->id ?? ''),
                // Where dispatch() was called; read from the trace instead when one is stored.
                'location' => $trace === null ? $this->dispatchLocation() : null,
                'trace' => $trace,
            ], fn ($value) => $value !== null),
            subtype: self::DISPATCH,
            userId: $this->monitor->lazyCurrentUserId(),
        );
    }

    /** Runs before the job is popped, scoping the reported peak memory to this one job. */
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

        // Before handle(), so everything it triggers lands on this attempt's timeline.
        $this->monitor->beginJobAttempt();

        $this->monitor->record(
            type: RecordType::Job,
            key: $event->job->resolveName(),
            payload: array_filter([
                'connection' => $event->connectionName,
                'queue' => $event->job->getQueue(),
                'job_id' => $this->correlationId($event->job),
                'attempts' => $event->job->attempts(),
                'server' => Host::name(),
                'started_at' => $this->startedAt[$id] ?? null,
                'popped_at' => $this->poppedAt[$id] ?? null,
            ], fn ($value) => $value !== null),
            subtype: self::PROCESSING,
        );

        // Persist now: if handle() fatals (OOM, signal), nothing later flushes this row.
        $this->monitor->flush();
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
                'server' => Host::name(),
                'peak_memory' => memory_get_peak_usage(true),
                // Exact JobProcessing time, preferred over created_at - duration.
                'started_at' => $this->startedAt[$id] ?? null,
                // When this attempt's JobPopping fired; absent if it never fired.
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
                'server' => Host::name(),
                'peak_memory' => memory_get_peak_usage(true),
                'exception' => get_class($event->exception),
                'message' => Str::limit($event->exception->getMessage(), 500),
                'started_at' => $this->startedAt[$id] ?? null,
                'popped_at' => $this->poppedAt[$id] ?? null,
            ], fn ($value) => $value !== null),
            duration: $this->duration($id),
            subtype: 'failed',
        );

        $this->monitor->endJobAttempt();

        $this->monitor->flush();
    }

    /** Released after an exception with attempts remaining; JobFailed fires only once retries run out. */
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
                'server' => Host::name(),
                'peak_memory' => memory_get_peak_usage(true),
                // backoff only exists on newer Laravel; `??` avoids an "Undefined property" error.
                'backoff' => $event->backoff ?? null,
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

        // round(x, 3) strips float noise from subtracting two epoch timestamps.
        return $startedAt !== null ? round((microtime(true) - $startedAt) * 1000, 3) : null;
    }

    /** First application frame behind the dispatch, as an absolute "file:line"; resolved at flush. */
    protected function dispatchLocation(): LazyValue
    {
        $frames = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 50);
        $location = $this->monitor->location;

        return new LazyValue(static function () use ($location, $frames): ?string {
            [$file, $line] = $location->forQueryTrace($frames);

            return $file !== null
                ? $location->absoluteFile($file).':'.($line ?? 0)
                : null;
        });
    }

    /**
     * Honors displayName() so queued mail/notification wrappers key by the
     * wrapped class, matching resolveName() on the outcome entries.
     */
    protected function displayName(mixed $job): string
    {
        if (! is_object($job)) {
            return (string) $job;
        }

        return method_exists($job, 'displayName') ? $job->displayName() : get_class($job);
    }

    /** Treats '' as absent (e.g. SyncJob::getJobId() always returns ''). */
    protected function jobId(string $id): ?string
    {
        return $id !== '' ? $id : null;
    }

    /** The 'job_id' value: payload uuid (stable across retries), else getJobId(). */
    protected function correlationId(QueueJob $job): ?string
    {
        return $this->jobId($job->uuid() ?? $job->getJobId());
    }

    private function resolveQueue(JobQueued $event): string
    {
        /**
         * Absent on some Laravel versions, mistyped on others.
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
            // get_object_vars() sees only public properties here, so a
            // protected $queue can't trigger "Cannot access protected property".
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
