<?php

namespace LaravelMonitor\State;

use LaravelMonitor\ExecutionStage;

/**
 * The HTTP request Monitor is tracking: its id plus the live lifecycle stage.
 *
 * @internal
 */
final class RequestState
{
    /**
     * Lifecycle phases passed through so far, in order; appended by Monitor::recordPhase().
     *
     * @var array<int, array{name: string, start: float, duration: float}>
     */
    public array $phases = [];

    /**
     * The view name or API resource class the controller returned; set by
     * Monitor::markRenderStart(), null for anything else.
     *
     * @var array{type: string, name: string}|null
     */
    public ?array $render = null;

    /**
     * @param  float  $currentExecutionStageStartedAtMicrotime  ms elapsed since the request started
     *                (as Monitor::elapsedMsPrecise()), not an absolute microtime()
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
