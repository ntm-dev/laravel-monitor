<?php

namespace LaravelMonitor\State;

use LaravelMonitor\ExecutionStage;

/**
 * The artisan command run Monitor is tracking; the console counterpart of RequestState.
 *
 * @internal
 */
final class CommandState
{
    /**
     * Lifecycle phases passed through so far, in order.
     *
     * @var array<int, array{name: string, start: float, duration: float}>
     */
    public array $phases = [];

    /** The pid the run started under, for Monitor::record()'s fork detection. */
    public ?int $pid = null;

    /** The dispatching scheduled task's run id, when this is a command-based task's subprocess. */
    public ?string $scheduledTaskRunId = null;

    /**
     * @param  float  $currentExecutionStageStartedAtMicrotime  ms elapsed since the run started,
     *                not an absolute microtime()
     */
    public function __construct(
        public float $timestamp,
        public string $id,
        public string $name,
        public ExecutionStage $stage = ExecutionStage::Bootstrap,
        public float $currentExecutionStageStartedAtMicrotime = 0.0,
        public int $hydratedModels = 0,
    ) {
    }
}
