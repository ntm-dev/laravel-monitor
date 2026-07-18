<?php

namespace LaravelMonitor\Recorders;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
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
    }

    public function recordQueued(JobQueued $event): void
    {
        $this->monitor->record(
            type: 'job',
            key: is_object($event->job) ? get_class($event->job) : (string) $event->job,
            payload: [
                'connection' => $event->connectionName,
                'queue' => method_exists($event, 'queue') || property_exists($event, 'queue')
                    ? ($event->queue ?? 'default')
                    : 'default',
            ],
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
            payload: [
                'connection' => $event->connectionName,
                'queue' => $event->job->getQueue(),
            ],
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
            payload: [
                'connection' => $event->connectionName,
                'queue' => $event->job->getQueue(),
                'exception' => get_class($event->exception),
                'message' => Str::limit($event->exception->getMessage(), 500),
            ],
            duration: $this->duration($event->job->getJobId() ?: spl_object_hash($event->job)),
            subtype: 'failed',
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
}
