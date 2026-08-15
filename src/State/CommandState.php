<?php

namespace LaravelMonitor\State;

use LaravelMonitor\ExecutionStage;

/**
 * The artisan command run Monitor.php is currently tracking — mirrors
 * RequestState for a console run instead of an HTTP request. Every
 * property here is one Monitor.php itself reads or writes; see
 * ExecutionStage for the stage sequence this walks through.
 *
 * @internal
 */
final class CommandState
{
    /**
     * Every individual lifecycle phase this run has passed through so far,
     * in order — see RequestState::$phases for why start/duration per phase
     * is what Support\Timeline's waterfall needs.
     *
     * @var array<int, array{name: string, start: float, duration: float}>
     */
    public array $phases = [];

    /**
     * The pid this run actually started under — see Monitor::record()'s
     * fork detection (`php artisan tinker` forks the whole REPL loop into a
     * child process that SIGKILLs itself, skipping shutdown functions).
     */
    public ?int $pid = null;

    /**
     * When this run is a command-based scheduled task's own subprocess, the
     * dispatching task's own run id — see Monitor::beginCommandRun().
     */
    public ?string $scheduledTaskRunId = null;

    /**
     * @param  float  $currentExecutionStageStartedAtMicrotime  ms elapsed
     *                since the run started (Monitor.php's own existing
     *                clock base), not an absolute microtime() — see
     *                RequestState's own note on this same parameter.
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
