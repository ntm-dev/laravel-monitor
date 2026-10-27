<?php

namespace LaravelMonitor;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\View\View as ViewContract;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use LaravelMonitor\Contracts\EntryWriter;
use LaravelMonitor\Models\MonitorUser;
use LaravelMonitor\Recorders\Jobs;
use LaravelMonitor\State\CommandState;
use LaravelMonitor\State\RequestState;
use LaravelMonitor\Support\LivewireSnapshot;
use LaravelMonitor\Support\Location;
use LaravelMonitor\Support\RecordType;
use LaravelMonitor\Support\Str as SupportStr;
use Throwable;
use WeakMap;

use function implode;
use function in_array;
use function is_string;
use function ltrim;
use function trim;

class Monitor
{
    private float $timestamp;

    /** @var Entry[] */
    protected array $entries = [];

    protected bool $recording = true;

    /**
     * The HTTP request being recorded, or null outside one. Its live `stage`
     * tags every entry recorded during the request (see record()).
     */
    protected ?RequestState $request = null;

    /** The buffered root `request` entry, finalised (phases, duration) on flush. */
    protected ?Entry $pendingRequest = null;

    /**
     * Queued job attempts being processed, outermost first. A stack because a
     * job can dispatch another synchronously; popping the inner attempt
     * restores the outer one as the correlation target.
     *
     * @var list<array{id: string, start: float, models: int, attempts: int}>
     */
    protected array $jobStack = [];

    /** The artisan command being run, or null outside one; its children correlate onto its own timeline. */
    protected ?CommandState $command = null;

    /** The buffered root `command` entry, finalised (phases, duration) on flush. */
    protected ?Entry $pendingCommand = null;

    /**
     * The in-process scheduled task (`Schedule::call()`) being run, or null.
     * A `Schedule::command()` task runs as its own artisan subprocess with
     * its own `command` entry, which only references this run's id.
     *
     * @var array{id: string, start: float, models: int}|null
     */
    protected ?array $scheduledTask = null;

    /**
     * isSelfRequest() results per request object and ignore-path set — keyed by
     * the request itself, since this singleton outlives a single request in tests.
     *
     * @var WeakMap<Request, array<string, bool>>|null
     */
    protected ?WeakMap $selfRequestCache = null;

    /** Entries buffered before an early flush — read from config once, not on every record(). */
    protected ?int $bufferLimit = null;

    /** @var list<Closure> Run at the start of every flush(), before the buffer is read. */
    protected array $flushingCallbacks = [];

    /**
     * Context key carrying a scheduled task's run id into a command-based
     * task's subprocess; Laravel's scheduler propagates Context there itself.
     */
    protected const SCHEDULED_TASK_CONTEXT_KEY = 'monitor_scheduled_task_id';

    public function __construct(
        public Application $app,
        public Location $location,
    ) {
    }

    public function timestamp(float $timestamp): float
    {
        return $this->timestamp ??= $timestamp;
    }

    public function laravelVersion(): ?string
    {
        try {
            return $this->app->version();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Guard-qualified ids of users logged out during this lifecycle, keyed by
     * guard; see rememberLoggedOutUser().
     *
     * @var array<string, string>
     */
    private array $rememberedUserIds = [];

    /**
     * The authenticated application user as "{guard}:{id}", checking the
     * default guard first and skipping the dashboard's own guard.
     */
    public function currentUserId(): int|string|null
    {
        foreach ($this->authGuardsToCheck() as $guard) {
            try {
                $resolved = Auth::guard($guard);

                // hasUser(), not user(): resolving an untouched guard would
                // run a query the Queries recorder then captures.
                if (! $resolved->hasUser()) {
                    continue;
                }

                $user = $resolved->user();
            } catch (Throwable) {
                continue;
            }

            if ($user !== null) {
                return "{$guard}:{$user->getAuthIdentifier()}";
            }
        }

        // Fall back to a user logged out earlier in this lifecycle.
        foreach ($this->authGuardsToCheck() as $guard) {
            if (isset($this->rememberedUserIds[$guard])) {
                return $this->rememberedUserIds[$guard];
            }
        }

        return null;
    }

    /**
     * Called on Logout, so entries recorded before the logout still resolve
     * their lazy user id once flush() runs.
     */
    public function rememberLoggedOutUser(string $guard, ?Authenticatable $user): void
    {
        if ($user === null) {
            return;
        }

        $this->rememberedUserIds[$guard] = "{$guard}:{$user->getAuthIdentifier()}";
    }

    /**
     * currentUserId(), deferred until the entry is written at flush(), when
     * the application has finished authenticating.
     *
     * @return LazyValue<int|string|null>
     */
    public function lazyCurrentUserId(): LazyValue
    {
        return new LazyValue($this->currentUserId(...));
    }

    /**
     * @return string[]
     */
    private function authGuardsToCheck(): array
    {
        $ordered = array_unique(array_filter([
            config('auth.defaults.guard'),
            ...array_keys(config('auth.guards', [])),
        ]));

        return array_values(array_diff($ordered, [MonitorUser::guardName()]));
    }

    /** Buffer a new entry; persisted on flush() or once the buffer limit is reached. */
    public function record(
        RecordType $type,
        string|LazyValue|null $key = null,
        array $payload = [],
        ?float $duration = null,
        ?string $subtype = null,
        int|string|LazyValue|null $userId = null,
    ): void {
        if (! $this->enabled()) {
            return;
        }

        // Tag the entry with the request's (or command's) live lifecycle stage.
        if ($type !== RecordType::Request && $this->request !== null) {
            $payload['phase'] = $this->request->stage->value;
        } elseif ($type !== RecordType::Command && $this->command !== null) {
            $payload['phase'] = $this->command->stage->value;
        }

        $entry = new Entry(
            $type->value,
            $key,
            $payload,
            $duration,
            $subtype,
            $userId,
            // Innermost context wins: a job run in-process during a request
            // correlates onto the job attempt, and a scheduled task onto its
            // own run rather than the enclosing schedule:run command.
            $this->currentJob()['id'] ?? $this->request?->id ?? $this->scheduledTask['id'] ?? $this->command?->id ?? null,
            $this->startOffsetFor($type, $duration, $subtype),
        );

        if ($type === RecordType::Request && $this->request !== null) {
            $this->pendingRequest = $entry;
        }

        if ($type === RecordType::Command && $this->command !== null) {
            $this->pendingCommand = $entry;
        }

        $this->entries[] = $entry;

        // Flush immediately in a forked child (e.g. tinker's PsySH loop): it
        // is SIGKILLed without shutdown, so its buffer would never flush.
        if (
            $this->command !== null
            && $this->command->pid !== null
            && function_exists('posix_getpid')
            && posix_getpid() !== $this->command->pid
        ) {
            $this->flush();
        } elseif (count($this->entries) >= ($this->bufferLimit ??= (int) $this->app['config']->get('monitor.buffer', 200))) {
            $this->flush();
        }
    }

    /**
     * Where on the active timeline this entry started, in ms ("now minus its
     * duration"); the root entry itself starts at zero.
     */
    protected function startOffsetFor(RecordType $type, ?float $duration, ?string $subtype = null): ?float
    {
        if ($this->request !== null) {
            if ($type === RecordType::Request) {
                return 0.0;
            }

            return max(0.0, $this->elapsedMsPrecise() - ($duration ?? 0));
        }

        if (($job = $this->currentJob()) !== null) {
            // Only the attempt's own outcome sits at zero; a dispatch or the
            // 'processing' marker is offset like any other child.
            if ($type === RecordType::Job && ! in_array($subtype, [Jobs::DISPATCH, Jobs::PROCESSING], true)) {
                return 0.0;
            }

            $elapsed = max(0.0, round((microtime(true) - $job['start']) * 1000, 3));

            return max(0.0, $elapsed - ($duration ?? 0));
        }

        if ($this->scheduledTask !== null) {
            if ($type === RecordType::ScheduledTask) {
                return 0.0;
            }

            $elapsed = max(0.0, round((microtime(true) - $this->scheduledTask['start']) * 1000, 3));

            return max(0.0, $elapsed - ($duration ?? 0));
        }

        if ($this->command !== null) {
            if ($type === RecordType::Command) {
                return 0.0;
            }

            $elapsed = max(0.0, round((microtime(true) - $this->command->timestamp) * 1000, 3));

            return max(0.0, $elapsed - ($duration ?? 0));
        }

        return null;
    }

    public function enabled(): bool
    {
        return $this->recording && (bool) $this->app['config']->get('monitor.enabled', true);
    }

    /**
     * Whether the current request targets the dashboard (or $ignorePaths),
     * by path or, for a Livewire update request, by its snapshot's page path.
     */
    public function isSelfRequest(array $ignorePaths = []): bool
    {
        if (! $this->app->bound('request')) {
            return false;
        }

        $request = $this->app['request'];
        $key = implode("\n", $ignorePaths);

        $this->selfRequestCache ??= new WeakMap;
        $cached = $this->selfRequestCache[$request] ?? [];

        if (! isset($cached[$key])) {
            $cached[$key] = $this->matchesSelfRequest($request, $ignorePaths);
            $this->selfRequestCache[$request] = $cached;
        }

        return $cached[$key];
    }

    /**
     * @param  list<string>  $ignorePaths
     */
    protected function matchesSelfRequest(Request $request, array $ignorePaths): bool
    {
        $patterns = [
            ...$ignorePaths,
            trim((string) $this->app['config']->get('monitor.path', 'monitor'), '/').'*',
        ];

        if (SupportStr::matchesAny($request->path(), $patterns)) {
            return true;
        }

        if (! $request->hasHeader('X-Livewire')) {
            return false;
        }

        foreach (LivewireSnapshot::paths($request) as $path) {
            if (SupportStr::matchesAny(ltrim($path, '/'), $patterns)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Start tracking the HTTP request, from the app->booted() callback. Offsets
     * count from LARAVEL_START (preferred over REQUEST_TIME_FLOAT so webserver
     * time is excluded), with the boot itself recorded as the Bootstrap phase.
     */
    public function beginRequest(): void
    {
        $this->rememberedUserIds = [];

        $this->request = new RequestState(
            timestamp: $this->timestamp ?? microtime(true),
            id: (string) Str::uuid(),
            stage: ExecutionStage::BeforeMiddleware,
        );

        $elapsed = $this->elapsedMsPrecise();

        $this->recordPhase(ExecutionStage::Bootstrap, 0, $elapsed);
        $this->request->currentExecutionStageStartedAtMicrotime = $elapsed;
    }

    public function requestId(): ?string
    {
        return $this->request?->id;
    }

    /** Starts tracking a queued job attempt (on JobProcessing); its children correlate onto it. */
    public function beginJobAttempt(): void
    {
        $this->rememberedUserIds = [];

        $this->jobStack[] = [
            'id' => (string) Str::uuid(),
            'start' => microtime(true),
            'models' => 0,
            'attempts' => 1,
        ];
    }

    public function jobAttemptId(): ?string
    {
        return $this->currentJob()['id'] ?? null;
    }

    /** Pops the innermost attempt, restoring any outer one as current. */
    public function endJobAttempt(): void
    {
        array_pop($this->jobStack);
    }

    /** @return array{id: string, start: float, models: int, attempts: int}|null */
    protected function currentJob(): ?array
    {
        return $this->jobStack === [] ? null : $this->jobStack[array_key_last($this->jobStack)];
    }

    /**
     * Starts tracking an artisan command run (on CommandStarting) under a
     * fresh id; its children correlate onto it.
     *
     * @param  ?string  $scheduledTaskRunId  the dispatching scheduled task's run id when this
     *                                 is a command-based task's subprocess; stored only as a cross-reference
     */
    public function beginCommandRun(string $name, ?string $scheduledTaskRunId = null): void
    {
        $this->rememberedUserIds = [];

        $this->command = new CommandState(
            // LARAVEL_START, so the boot before CommandStarting counts as Bootstrap.
            timestamp: $this->timestamp ?? microtime(true),
            id: (string) Str::uuid(),
            name: $name,
            stage: ExecutionStage::Action,
        );

        // For record()'s fork detection.
        $this->command->pid = function_exists('posix_getpid') ? posix_getpid() : null;
        $this->command->scheduledTaskRunId = $scheduledTaskRunId;

        $elapsed = $this->commandElapsedMsPrecise() ?? 0.0;

        $this->recordCommandPhase(ExecutionStage::Bootstrap, 0, $elapsed);
        $this->command->currentExecutionStageStartedAtMicrotime = $elapsed;
    }

    /**
     * Milliseconds since the running command's process started, or null
     * outside one; rounded as in elapsedMsPrecise().
     */
    public function commandElapsedMsPrecise(): ?float
    {
        if ($this->command === null) {
            return null;
        }

        return max(0.0, round((microtime(true) - $this->command->timestamp) * 1000, 3));
    }

    /** Append a lifecycle phase for the running command (offsets/durations in ms, microsecond precision). */
    public function recordCommandPhase(ExecutionStage $stage, int|float $start, int|float $duration): void
    {
        if ($this->command === null) {
            return;
        }

        $this->command->phases[] = [
            'name' => $stage->value,
            'start' => max(0, $start),
            'duration' => max(0, $duration),
        ];
    }

    /** Marks the action → terminating boundary, on CommandFinished. */
    public function markCommandTerminating(): void
    {
        $this->transitionCommandStage(ExecutionStage::Terminating);
    }

    /** Marks the terminating → end boundary, from Hooks\CommandLifecycleEndHook. */
    public function markCommandEnd(): void
    {
        $this->transitionCommandStage(ExecutionStage::End);
    }

    /** Move the running command to a new stage, closing out the previous phase. */
    protected function transitionCommandStage(ExecutionStage $stage): void
    {
        if ($this->command === null || $this->command->stage === $stage) {
            return;
        }

        $now = $this->commandElapsedMsPrecise();

        $this->recordCommandPhase($this->command->stage, $this->command->currentExecutionStageStartedAtMicrotime, $now - $this->command->currentExecutionStageStartedAtMicrotime);

        $this->command->stage = $stage;
        $this->command->currentExecutionStageStartedAtMicrotime = $now;
    }

    public function commandRunId(): ?string
    {
        return $this->command?->id;
    }

    /** The running command's name, e.g. "app:sync-data" — used to label queries recorded outside a request/job. */
    public function commandName(): ?string
    {
        return $this->command?->name;
    }

    /** Called by the Commands recorder once the run's own `command` entry has been recorded. */
    public function endCommandRun(): void
    {
        $this->command = null;
    }

    /**
     * Starts tracking a scheduled task run (on ScheduledTaskStarting); the id
     * is also put on Context for a command-based task's subprocess.
     */
    public function beginScheduledTaskRun(): void
    {
        $this->rememberedUserIds = [];

        $id = (string) Str::uuid();

        $this->scheduledTask = [
            'id' => $id,
            'start' => microtime(true),
            'models' => 0,
        ];

        // Context is Laravel 11+; on 10 the subprocess just isn't linked back.
        if (class_exists(Context::class)) {
            Context::add(self::SCHEDULED_TASK_CONTEXT_KEY, $id);
        }
    }

    public function scheduledTaskRunId(): ?string
    {
        return $this->scheduledTask['id'] ?? null;
    }

    /** Called by the ScheduledTasks recorder once the run's own `scheduled_task` entry has been recorded. */
    public function endScheduledTaskRun(): void
    {
        $this->scheduledTask = null;

        if (class_exists(Context::class)) {
            Context::forget(self::SCHEDULED_TASK_CONTEXT_KEY);
        }
    }

    /**
     * The scheduled task run id inherited via Context from the parent
     * scheduler process, or null when the command runs standalone.
     */
    public function inheritedScheduledTaskRunId(): ?string
    {
        if (! class_exists(Context::class)) {
            return null;
        }

        $id = Context::get(self::SCHEDULED_TASK_CONTEXT_KEY);

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * Correlation id pairing a mail-channel notification with the `mail`
     * entry it produces; set only while that send is in flight.
     */
    protected ?string $pendingNotificationCorrelationId = null;

    /** Starts a correlation window for a mail-channel notification and returns its id. */
    public function beginNotificationDispatch(): string
    {
        return $this->pendingNotificationCorrelationId = (string) Str::uuid();
    }

    /** The in-flight correlation id, or null outside a mail-channel notification dispatch. */
    public function pendingNotificationCorrelationId(): ?string
    {
        return $this->pendingNotificationCorrelationId;
    }

    public function endNotificationDispatch(): void
    {
        $this->pendingNotificationCorrelationId = null;
    }

    /** Count a query executed during the request, stored as the request's `query_count`. */
    public function incrementQueryCount(): void
    {
        if ($this->request !== null && $this->enabled()) {
            $this->request->queries++;
        }
    }

    public function queryCount(): int
    {
        return $this->request?->queries ?? 0;
    }

    /** Count every Eloquent model hydrated in the active request, job attempt, scheduled task or command. */
    public function incrementModelCount(): void
    {
        if (! $this->enabled()) {
            return;
        }

        if ($this->request !== null) {
            $this->request->hydratedModels++;
        } elseif ($this->jobStack !== []) {
            $this->jobStack[array_key_last($this->jobStack)]['models']++;
        } elseif ($this->scheduledTask !== null) {
            $this->scheduledTask['models']++;
        } elseif ($this->command !== null) {
            $this->command->hydratedModels++;
        }
    }

    public function modelCount(): int
    {
        return $this->request?->hydratedModels ?? $this->currentJob()['models'] ?? $this->scheduledTask['models'] ?? $this->command?->hydratedModels ?? 0;
    }

    /** Milliseconds elapsed since the request started, or null outside one. */
    public function elapsedMs(): ?int
    {
        if ($this->request === null) {
            return null;
        }

        return max(0, (int) round((microtime(true) - $this->request->timestamp) * 1000));
    }

    /**
     * Milliseconds since the request started, at microsecond precision.
     * round(x, 3) strips float noise from subtracting two epoch timestamps.
     */
    public function elapsedMsPrecise(): ?float
    {
        if ($this->request === null) {
            return null;
        }

        return max(0.0, round((microtime(true) - $this->request->timestamp) * 1000, 3));
    }

    /** Marks the middleware → action boundary, from the route's last middleware (Hooks\ControllerStartHook). */
    public function markControllerStart(): void
    {
        $this->transitionStage(ExecutionStage::Action, from: ExecutionStage::BeforeMiddleware);
    }

    /** Marks the action → render boundary, on PreparingResponse (still inside the route's middleware). */
    public function markRenderStart(mixed $event = null): void
    {
        if ($this->request !== null && $this->request->stage === ExecutionStage::Action) {
            $this->request->render = $this->describeRender($event?->response ?? null);
        }

        $this->transitionStage(ExecutionStage::Render, from: ExecutionStage::Action);
    }

    /**
     * The view name or API resource class behind what the controller
     * returned; reads already-resolved state only, never triggers a query.
     *
     * @return array{type: string, name: string}|null
     */
    protected function describeRender(mixed $response): ?array
    {
        if ($response instanceof ViewContract) {
            return ['type' => 'view', 'name' => $response->name()];
        }

        if ($response instanceof JsonResource || $response instanceof ResourceCollection) {
            return ['type' => 'resource', 'name' => $response::class];
        }

        return null;
    }

    /** Marks the render → after-middleware boundary, on ResponsePrepared. */
    public function markUnwinding(): void
    {
        $this->transitionStage(ExecutionStage::AfterMiddleware, from: ExecutionStage::Render);
    }

    /**
     * Advances to after-middleware when an uncaught exception skips the
     * response events; never moves the stage backward.
     */
    public function markUnhandledException(): void
    {
        if ($this->request === null) {
            return;
        }

        $stagesBeforeUnwinding = [
            ExecutionStage::Bootstrap,
            ExecutionStage::BeforeMiddleware,
            ExecutionStage::Action,
            ExecutionStage::Render,
        ];

        if (in_array($this->request->stage, $stagesBeforeUnwinding, true)) {
            $this->transitionStage(ExecutionStage::AfterMiddleware);
        }
    }

    /** Opens the sending phase on RequestHandled, closing whatever stage is still open. */
    public function markResponseReady(): void
    {
        $this->transitionStage(ExecutionStage::Sending);
    }

    /** Marks the start of the terminating phase (response already sent). */
    public function markTerminating(): void
    {
        $this->transitionStage(ExecutionStage::Terminating);
    }

    /** Marks the terminating → end boundary, from Hooks\RequestLifecycleEndHook. */
    public function markEnd(): void
    {
        $this->transitionStage(ExecutionStage::End);
    }

    /**
     * Whether a request or command run will flush from its own lifecycle-end
     * hook, so the earlier app->terminating() flush must be skipped.
     */
    public function hasTrackedExecution(): bool
    {
        return $this->request !== null || $this->command !== null;
    }

    /**
     * Move the request to a new lifecycle stage, closing out the previous phase.
     *
     * @param  ?ExecutionStage  $from  if given, the transition only applies from this stage
     *                                 (guards against events firing out of order or twice)
     */
    protected function transitionStage(ExecutionStage $stage, ?ExecutionStage $from = null): void
    {
        if ($this->request === null || $this->request->stage === $stage) {
            return;
        }

        if ($from !== null && $this->request->stage !== $from) {
            return;
        }

        $now = $this->elapsedMsPrecise();

        $this->recordPhase($this->request->stage, $this->request->currentExecutionStageStartedAtMicrotime, $now - $this->request->currentExecutionStageStartedAtMicrotime);

        $this->request->stage = $stage;
        $this->request->currentExecutionStageStartedAtMicrotime = $now;
    }

    /** Append a lifecycle phase (offsets/durations in ms, microsecond precision). */
    public function recordPhase(ExecutionStage $stage, int|float $start, int|float $duration): void
    {
        if ($this->request === null) {
            return;
        }

        $this->request->phases[] = [
            'name' => $stage->value,
            'start' => max(0, $start),
            'duration' => max(0, $duration),
        ];
    }

    /**
     * Complete the root `request` entry before storing: close the open stage,
     * attach phases and counts, and extend duration to the full lifecycle.
     */
    protected function finalizePendingRequest(): void
    {
        $entry = $this->pendingRequest;

        if ($entry === null || $this->request === null) {
            return;
        }

        $this->pendingRequest = null;

        $elapsed = $this->elapsedMsPrecise();

        $this->recordPhase($this->request->stage, $this->request->currentExecutionStageStartedAtMicrotime, $elapsed - $this->request->currentExecutionStageStartedAtMicrotime);

        $entry->payload['phases'] = $this->request->phases;

        if ($this->request->render !== null) {
            $entry->payload['render'] = $this->request->render;
        }

        $entry->payload['query_count'] = $this->request->queries;
        $entry->payload['model_count'] = $this->request->hydratedModels;
        $entry->duration = max($entry->duration ?? 0.0, $elapsed ?? 0.0);
    }

    /**
     * Complete the root `command` entry before storing, like
     * finalizePendingRequest(): duration covers bootstrap through terminating.
     */
    protected function finalizePendingCommand(): void
    {
        $entry = $this->pendingCommand;

        if ($entry === null || $this->command === null) {
            return;
        }

        $this->pendingCommand = null;

        $elapsed = $this->commandElapsedMsPrecise();

        $this->recordCommandPhase($this->command->stage, $this->command->currentExecutionStageStartedAtMicrotime, $elapsed - $this->command->currentExecutionStageStartedAtMicrotime);

        $entry->payload['phases'] = $this->command->phases;
        // created_at is second-precision and marks the end; see Support\Format::startedAt().
        $entry->payload['started_at'] = $this->command->timestamp;

        // Links a scheduled task's subprocess to its scheduled_task entry
        // (and back) via findByCorrelationId().
        if ($this->command->scheduledTaskRunId !== null) {
            $entry->payload['correlation_id'] = $this->command->scheduledTaskRunId;
        }

        $entry->duration = max($entry->duration ?? 0.0, $elapsed ?? 0.0);
    }

    /** Persist all buffered entries; recording is paused so the writes aren't captured. */
    public function flush(): void
    {
        foreach ($this->flushingCallbacks as $callback) {
            $callback();
        }

        $this->finalizePendingRequest();
        $this->finalizePendingCommand();

        if ($this->entries === []) {
            return;
        }

        $entries = $this->entries;
        $this->entries = [];

        $this->recording = false;

        try {
            $this->storage()->store($entries);
        } catch (Throwable $e) {
            // Never break the app, but keep storage failures diagnosable.
            $this->reportStorageFailure($e);
        } finally {
            $this->recording = true;
        }
    }

    /**
     * Register a callback that runs at the start of every flush(), so a
     * recorder can still buffer something it was holding back.
     */
    public function flushing(Closure $callback): void
    {
        $this->flushingCallbacks[] = $callback;
    }

    /**
     * Report a storage failure through the app's exception handler.
     * Recording is already paused by flush(), so this cannot recurse into
     * the Exceptions recorder.
     */
    protected function reportStorageFailure(Throwable $e): void
    {
        try {
            $this->app->make(\Illuminate\Contracts\Debug\ExceptionHandler::class)->report($e);
        } catch (Throwable) {
            // The exception handler itself is unavailable; nothing more we can do.
        }
    }

    /**
     * Run a callback without recording anything it triggers.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function ignore(callable $callback): mixed
    {
        $previous = $this->recording;
        $this->recording = false;

        try {
            return $callback();
        } finally {
            $this->recording = $previous;
        }
    }

    public function stopRecording(): void
    {
        $this->recording = false;
    }

    public function startRecording(): void
    {
        $this->recording = true;
    }

    public function storage(): EntryWriter
    {
        return $this->app->make(EntryWriter::class);
    }
}
