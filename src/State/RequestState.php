<?php

namespace LaravelMonitor\State;

use LaravelMonitor\ExecutionStage;

/**
 * The HTTP request Monitor.php is currently tracking — a shared identity
 * plus a *live* lifecycle stage pointer, replacing the request-tracking
 * fields Monitor::$request used to keep as a loose array. Every property
 * here is one Monitor.php itself reads or writes; see ExecutionStage for
 * the stage sequence this walks through.
 *
 * @internal
 */
final class RequestState
{
    /**
     * Every individual lifecycle phase this request has passed through so
     * far, in order — start and duration per phase, so Support\Timeline's
     * waterfall can position each one's bar. Appended by
     * Monitor::recordPhase() on every stage transition.
     *
     * @var array<int, array{name: string, start: float, duration: float}>
     */
    public array $phases = [];

    /**
     * @param  float  $currentExecutionStageStartedAtMicrotime  ms elapsed
     *                since the request started (Monitor.php's own existing
     *                clock base), not an absolute microtime() — so it keeps
     *                diffing correctly against Monitor::elapsedMsPrecise().
     */
    public function __construct(
        public float $timestamp,
        public string $id,
        public ExecutionStage $stage = ExecutionStage::Bootstrap,
        public float $currentExecutionStageStartedAtMicrotime = 0.0,
        public int $queries = 0,
        public int $hydratedModels = 0,
    ) {
    }
}
