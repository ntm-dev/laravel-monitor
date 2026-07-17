<?php

namespace LaravelMonitor\Support;

/**
 * App-wide settings overrides for the values that config/monitor.php ships as
 * defaults (the Environment + Recorders sections of the dashboard).
 *
 * Unlike {@see Preferences} (per-viewer, cookie), these are shared by the whole
 * application and affect recording behaviour, so they are persisted server-side
 * in a JSON file. {@see apply()} overlays whatever is stored onto the live
 * config at boot — a stored value wins, and anything not stored keeps its
 * config/monitor.php default ("nếu chưa cài đặt thì lấy mặc định").
 */
class Settings
{
    /** Scalar override key => config path it maps onto. */
    protected const SCALARS = [
        'enabled' => 'monitor.enabled',
        'storage_driver' => 'monitor.storage.driver',
        'database_table' => 'monitor.storage.database.table',
        'dashboard_path' => 'monitor.path',
        'retention_hours' => 'monitor.retention.hours',
        'refresh' => 'monitor.refresh',
        'request_threshold' => 'monitor.thresholds.request',
        'job_threshold' => 'monitor.thresholds.job',
        'query_threshold' => 'monitor.thresholds.query',
        'outgoing_request_threshold' => 'monitor.thresholds.outgoing_request',
    ];

    /** @var array<string, mixed>|null In-request cache of the decoded store. */
    protected static ?array $cache = null;

    /** The config-file dashboard path, captured before any override is applied. */
    protected static ?string $defaultPath = null;

    /**
     * Overlay stored overrides onto the live config. Call once, early in the
     * service provider boot, before recorders and routes are registered.
     */
    public static function apply(): void
    {
        // Snapshot the config-file default path before overriding it, so a save
        // or reset can redirect to the correct URL instead of a stale one.
        static::$defaultPath = (string) config('monitor.path', 'monitor');

        $stored = static::all();

        if ($stored === []) {
            return;
        }

        foreach (self::SCALARS as $key => $path) {
            if (array_key_exists($key, $stored)) {
                config([$path => $stored[$key]]);
            }
        }

        if (isset($stored['periods']) && is_array($stored['periods']) && $stored['periods'] !== []) {
            config(['monitor.periods' => $stored['periods']]);
        }

        if (isset($stored['recorders']) && is_array($stored['recorders'])) {
            $classes = static::recorderClasses();

            foreach ($stored['recorders'] as $name => $enabled) {
                if (isset($classes[$name])) {
                    config(['monitor.recorders.'.$classes[$name].'.enabled' => (bool) $enabled]);
                }
            }
        }
    }

    /**
     * The effective values shown in the settings form. Reads live config, so it
     * already reflects any applied overrides layered over the defaults.
     *
     * @return array<string, mixed>
     */
    public static function current(): array
    {
        return [
            'enabled' => (bool) config('monitor.enabled', true),
            'storage_driver' => (string) config('monitor.storage.driver', 'database'),
            'database_table' => (string) config('monitor.storage.database.table', 'monitor_entries'),
            'dashboard_path' => trim((string) config('monitor.path', 'monitor'), '/'),
            'retention_hours' => (int) config('monitor.retention.hours', 168),
            'refresh' => (int) config('monitor.refresh', 10),
            'periods' => (array) config('monitor.periods', []),
            'request_threshold' => (int) config('monitor.thresholds.request', 1000),
            'job_threshold' => (int) config('monitor.thresholds.job', 1000),
            'query_threshold' => (int) config('monitor.thresholds.query', 500),
            'outgoing_request_threshold' => (int) config('monitor.thresholds.outgoing_request', 1000),
            'recorders' => static::recorders(),
            'is_customized' => static::all() !== [],
        ];
    }

    /** Recorder basename => sidebar icon shown next to its toggle. */
    protected const RECORDER_ICONS = [
        'Requests' => Icons::REQUESTS,
        'SlowQueries' => Icons::QUERIES,
        'Exceptions' => Icons::EXCEPTIONS,
        'Logs' => Icons::LOGS,
        'Jobs' => Icons::JOBS,
        'ScheduledTasks' => Icons::SCHEDULE,
        'CacheInteractions' => Icons::CACHE,
        'OutgoingRequests' => Icons::OUTGOING,
        'Notifications' => Icons::NOTIFICATIONS,
        'Mail' => Icons::MAIL,
        'Authentication' => Icons::USER,
    ];

    /**
     * Recorder rows for the form: display name, icon and enabled state.
     *
     * @return list<array{name: string, icon: string, enabled: bool}>
     */
    public static function recorders(): array
    {
        $rows = [];

        foreach (config('monitor.recorders', []) as $class => $options) {
            $name = class_basename($class);

            $rows[] = [
                'name' => $name,
                'icon' => self::RECORDER_ICONS[$name] ?? Icons::BELL_ALERT,
                'enabled' => (bool) ($options['enabled'] ?? true),
            ];
        }

        return $rows;
    }

    /**
     * Storage drivers offered by the picker (built-in plus the current value).
     *
     * @return list<string>
     */
    public static function storageDrivers(): array
    {
        return array_values(array_unique(['database', (string) config('monitor.storage.driver', 'database')]));
    }

    /**
     * Persist the given overrides, replacing any existing store.
     *
     * @param  array<string, mixed>  $values
     */
    public static function save(array $values): void
    {
        static::write($values);
    }

    /** The config-file dashboard path (ignoring overrides), trimmed of slashes. */
    public static function defaultPath(): string
    {
        return trim(static::$defaultPath ?? (string) config('monitor.path', 'monitor'), '/');
    }

    /** Clear all overrides so config/monitor.php defaults apply again. */
    public static function reset(): void
    {
        $path = static::path();

        if (is_file($path)) {
            @unlink($path);
        }

        static::$cache = [];
    }

    /**
     * The stored overrides, or an empty array when nothing has been saved.
     *
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        if (static::$cache !== null) {
            return static::$cache;
        }

        $path = static::path();

        if (! is_file($path)) {
            return static::$cache = [];
        }

        $data = json_decode((string) file_get_contents($path), true);

        return static::$cache = is_array($data) ? $data : [];
    }

    /**
     * Map of recorder basename => fully-qualified class, from config.
     *
     * @return array<string, string>
     */
    public static function recorderClasses(): array
    {
        $map = [];

        foreach (array_keys(config('monitor.recorders', [])) as $class) {
            $map[class_basename($class)] = $class;
        }

        return $map;
    }

    protected static function path(): string
    {
        return storage_path('app/monitor-settings.json');
    }

    /**
     * @param  array<string, mixed>  $values
     */
    protected static function write(array $values): void
    {
        $path = static::path();
        $dir = dirname($path);

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($path, json_encode($values, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);

        static::$cache = $values;
    }
}
