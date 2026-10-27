<?php

namespace LaravelMonitor\Support;

use LaravelMonitor\Recorders\Requests;

use function array_chunk;
use function array_key_exists;
use function array_keys;
use function array_map;
use function array_reverse;
use function array_values;
use function ceil;
use function count;
use function implode;
use function intdiv;
use function is_array;
use function max;
use function str_starts_with;
use function strlen;
use function substr;

/**
 * App-wide overrides of config/monitor.php, saved from the Settings page as a
 * PHP file (see all()). {@see apply()} overlays them onto config at boot;
 * anything not stored keeps its config default.
 */
class Settings
{
    /** Scalar override key => config path it maps onto. */
    protected const SCALARS = [
        'enabled' => 'monitor.enabled',
        'dashboard_path' => 'monitor.path',
        'retention_hours' => 'monitor.retention.hours',
        'refresh' => 'monitor.refresh',
        'request_threshold' => 'monitor.thresholds.request',
        'job_threshold' => 'monitor.thresholds.job',
        'query_threshold' => 'monitor.thresholds.query',
        'outgoing_request_threshold' => 'monitor.thresholds.outgoing_request',
        'aggregate_cache_enabled' => 'monitor.aggregate_cache.enabled',
        // null means "the host app's own cache.default" — see BuildsQueries::cacheRemember().
        'aggregate_cache_store' => 'monitor.aggregate_cache.store',
        'aggregate_cache_mode' => 'monitor.aggregate_cache.mode',
        'request_body_max_bytes' => 'monitor.recorders.'.Requests::class.'.details.max_body_bytes',
    ];

    /** Where the aggregate cache's driver options come from: the app's own store, this package's defaults, or the saved options. */
    public const AGGREGATE_CACHE_MODES = ['app', 'default', 'custom'];

    /** Body-size unit => bytes, smallest first (see splitBodyBytes()). */
    public const BODY_SIZE_UNITS = ['B' => 1, 'KB' => 1024, 'MB' => 1024 * 1024];

    /** Largest stored-body cap the Settings form accepts, in bytes. */
    public const MAX_BODY_BYTES = 10 * 1024 * 1024;

    /** Text an admin must type to switch the master switch off — that also unregisters the dashboard. */
    public const DISABLE_CONFIRMATION = 'disable';

    /**
     * Table config path => suffix appended to a stored `table_prefix` override;
     * mirrors the tables config/monitor.php derives from $tablePrefix.
     */
    protected const TABLE_SUFFIXES = [
        'monitor.storage.database.table' => 'entries',
        'monitor.aggregates.table' => 'aggregates',
        'monitor.issues.table' => 'issues',
        'monitor.auth.table' => 'users',
        'monitor.auth.invitations_table' => 'invitations',
        'monitor.auth.password_resets_table' => 'password_resets',
        'monitor.auth.email_changes_table' => 'email_changes',
        'monitor.auth.webauthn_table' => 'webauthn_credentials',
        'monitor.auth.oauth_accounts_table' => 'oauth_accounts',
        'monitor.aggregate_cache.table' => 'cache',
    ];

    /**
     * Every TABLE_SUFFIXES value, for the Settings form's live table-name preview.
     *
     * @return list<string>
     */
    public static function tableSuffixes(): array
    {
        return array_values(self::TABLE_SUFFIXES);
    }

    /**
     * The host app's cache stores for the aggregate cache picker, always including 'file'.
     *
     * @return list<string>
     */
    public static function aggregateCacheStores(): array
    {
        return array_values(array_unique([
            'file',
            ...array_keys((array) config('cache.stores', [])),
        ]));
    }

    /**
     * Editable option fields per aggregate-cache driver: the only options that
     * reach BuildsQueries::aggregateCacheStore() (filtered again in applyAggregateCache()).
     */
    protected const AGGREGATE_CACHE_OPTION_FIELDS = [
        'file' => ['path', 'lock_path'],
        // No 'table' here — it always follows table_prefix (TABLE_SUFFIXES).
        'database' => ['connection'],
    ];

    /** Drivers configured by credentials, never edited or shown by the form — set via .env instead. */
    protected const AGGREGATE_CACHE_SENSITIVE_DRIVERS = ['redis', 'memcached', 'dynamodb'];

    /** A store's driver from config('cache.stores'), falling back to the store name. */
    public static function aggregateCacheDriver(string $store): string
    {
        return (string) (config("cache.stores.{$store}.driver") ?? $store);
    }

    /**
     * @return list<string>
     */
    public static function aggregateCacheOptionFields(string $store): array
    {
        return self::AGGREGATE_CACHE_OPTION_FIELDS[static::aggregateCacheDriver($store)] ?? [];
    }

    /**
     * What "default" mode builds the isolated store from, per driver option.
     *
     * @return array<string, string|null>
     */
    public static function aggregateCacheDefaults(string $driver): array
    {
        return match ($driver) {
            'file' => [
                'path' => storage_path('framework/cache/monitor-aggregate'),
                'lock_path' => storage_path('framework/cache/monitor-aggregate'),
            ],
            // Where the migration creates the cache table.
            'database' => ['connection' => config('monitor.storage.database.connection') ?: config('database.default')],
            default => [],
        };
    }

    /**
     * Names the 'database' cache driver's connection can be picked from.
     *
     * @return list<string>
     */
    public static function databaseConnections(): array
    {
        return array_map('strval', array_keys((array) config('database.connections', [])));
    }

    public static function aggregateCacheIsSensitive(string $store): bool
    {
        return in_array(static::aggregateCacheDriver($store), self::AGGREGATE_CACHE_SENSITIVE_DRIVERS, true);
    }

    /** Whether the host app has its own `cache.stores.$driver` entry to offer reusing. */
    public static function aggregateCacheHasAppConfig(string $driver): bool
    {
        return (array) config("cache.stores.{$driver}", []) !== [];
    }

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
        // Snapshot the config-file path before overriding it, for defaultPath().
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

        static::applyAggregateCache($stored);

        if (isset($stored['periods']) && is_array($stored['periods']) && $stored['periods'] !== []) {
            config(['monitor.periods' => $stored['periods']]);
        }

        // One prefix override fans out to every TABLE_SUFFIXES table.
        if (array_key_exists('table_prefix', $stored)) {
            config(['monitor.table_prefix' => $stored['table_prefix']]);

            foreach (self::TABLE_SUFFIXES as $path => $suffix) {
                config([$path => $stored['table_prefix'].$suffix]);
            }
        }

        static::applyRecorders($stored);
        static::applyRecorderDetails($stored);
    }

    /**
     * @param  array<string, mixed>  $stored
     */
    protected static function applyAggregateCache(array $stored): void
    {
        // Legacy file without a mode: derive it from the old "use app config" toggle.
        if (! array_key_exists('aggregate_cache_mode', $stored)) {
            $legacyMode = ($stored['aggregate_cache_use_app_config'] ?? false)
                ? 'app'
                : (($stored['aggregate_cache_options'] ?? []) !== [] ? 'custom' : null);

            if ($legacyMode !== null) {
                config(['monitor.aggregate_cache.mode' => $legacyMode]);
            }
        }

        // Re-filter to AGGREGATE_CACHE_OPTION_FIELDS regardless of what's on disk:
        // this is the layer BuildsQueries::aggregateCacheStore() trusts.
        if (isset($stored['aggregate_cache_options']) && is_array($stored['aggregate_cache_options'])) {
            $store = $stored['aggregate_cache_store'] ?? config('monitor.aggregate_cache.store');
            $allowed = static::aggregateCacheOptionFields((string) $store);

            config(['monitor.aggregate_cache.options' => array_intersect_key($stored['aggregate_cache_options'], array_flip($allowed))]);
        }
    }

    /**
     * @param  array<string, mixed>  $stored
     */
    protected static function applyRecorders(array $stored): void
    {
        if (! isset($stored['recorders']) || ! is_array($stored['recorders'])) {
            return;
        }

        $classes = static::recorderClasses();

        foreach ($stored['recorders'] as $name => $enabled) {
            $name = self::LEGACY_RECORDER_ALIASES[$name] ?? $name;

            if (isset($classes[$name])) {
                config(['monitor.recorders.'.$classes[$name].'.enabled' => (bool) $enabled]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $stored
     */
    protected static function applyRecorderDetails(array $stored): void
    {
        if (! isset($stored['recorder_details']) || ! is_array($stored['recorder_details'])) {
            return;
        }

        $classes = static::recorderClasses();

        foreach ($stored['recorder_details'] as $name => $details) {
            $name = self::LEGACY_RECORDER_ALIASES[$name] ?? $name;

            if (! isset($classes[$name]) || ! is_array($details)) {
                continue;
            }

            foreach ($details as $key => $enabled) {
                if (array_key_exists($key, self::RECORDER_DETAILS[$name] ?? [])) {
                    config(['monitor.recorders.'.$classes[$name].'.details.'.$key => (bool) $enabled]);
                }
            }
        }
    }

    /**
     * The effective values for the settings form, read from live (overridden) config.
     *
     * @return array<string, mixed>
     */
    public static function current(): array
    {
        return [
            'enabled' => (bool) config('monitor.enabled', true),
            'table_prefix' => (string) config('monitor.table_prefix', 'monitor_'),
            'tableSuffixes' => static::tableSuffixes(),
            'dashboard_path' => trim((string) config('monitor.path', 'monitor'), '/'),
            'retention_hours' => (int) config('monitor.retention.hours', 168),
            'refresh' => (int) config('monitor.refresh', 10),
            'periods' => (array) config('monitor.periods', []),
            'request_threshold' => (int) config('monitor.thresholds.request', 1000),
            'job_threshold' => (int) config('monitor.thresholds.job', 1000),
            'query_threshold' => (int) config('monitor.thresholds.query', 500),
            'outgoing_request_threshold' => (int) config('monitor.thresholds.outgoing_request', 1000),
            'request_body_max_bytes' => (int) config('monitor.recorders.'.Requests::class.'.details.max_body_bytes', 64 * 1024),
            'aggregate_cache_enabled' => (bool) config('monitor.aggregate_cache.enabled', true),
            'aggregate_cache_store' => (string) (config('monitor.aggregate_cache.store') ?? ''),
            'aggregate_cache_stores' => static::aggregateCacheStores(),
            'aggregate_cache_options' => (array) config('monitor.aggregate_cache.options', []),
            'aggregate_cache_option_fields' => self::AGGREGATE_CACHE_OPTION_FIELDS,
            // store name => driver, for the form to look up the selected store's lists above.
            'aggregate_cache_store_drivers' => collect(static::aggregateCacheStores())
                ->mapWithKeys(fn (string $store) => [$store => static::aggregateCacheDriver($store)])
                ->all(),
            'aggregate_cache_sensitive_drivers' => self::AGGREGATE_CACHE_SENSITIVE_DRIVERS,
            'aggregate_cache_mode' => (string) config('monitor.aggregate_cache.mode', 'default'),
            'aggregate_cache_connections' => static::databaseConnections(),
            'aggregate_cache_defaults' => collect(array_keys(self::AGGREGATE_CACHE_OPTION_FIELDS))
                ->mapWithKeys(fn (string $driver) => [$driver => static::aggregateCacheDefaults($driver)])
                ->all(),
            // driver => whether the host app has its own store for it.
            'aggregate_cache_app_config_available' => collect(array_keys(self::AGGREGATE_CACHE_OPTION_FIELDS))
                ->mapWithKeys(fn (string $driver) => [$driver => static::aggregateCacheHasAppConfig($driver)])
                ->all(),
            'recorders' => static::recorders(),
            'recorderColumns' => static::recorderColumns(),
            'recordersWarning' => static::recordersWarning(),
            'is_customized' => static::all() !== [],
        ];
    }

    /** Old recorder basename => current one, so older settings files still resolve. */
    protected const LEGACY_RECORDER_ALIASES = [
        'SlowQueries' => 'Queries',
    ];

    /** Highest-volume recorders (off by default outside local), named in recordersWarning(). */
    protected const HEAVY_RECORDERS = ['Queries', 'Models', 'CacheInteractions'];

    /**
     * Detail sub-options per recorder: basename => [config key => label translation key].
     * Each is a toggle nested under its recorder's row; off by default outside local.
     */
    protected const RECORDER_DETAILS = [
        'Requests' => ['record_response_body' => 'monitor::messages.settings.recorder_detail_response_body'],
        'Queries' => ['trace' => 'monitor::messages.settings.recorder_detail_trace'],
        'Jobs' => ['trace' => 'monitor::messages.settings.recorder_detail_trace'],
        'OutgoingRequests' => [
            'trace' => 'monitor::messages.settings.recorder_detail_trace',
            'record_body' => 'monitor::messages.settings.recorder_detail_body',
        ],
        'Mail' => ['record_body' => 'monitor::messages.settings.recorder_detail_body'],
        'Notifications' => ['record_data' => 'monitor::messages.settings.recorder_detail_data'],
    ];

    /** Recorder basename => sidebar icon shown next to its toggle. */
    protected const RECORDER_ICONS = [
        'Requests' => Icons::REQUESTS,
        'Queries' => Icons::QUERIES,
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
     * Recorder rows for the form: name, icon, enabled state and detail toggles.
     *
     * @return list<array{name: string, icon: string, enabled: bool, details: list<array{key: string, label: string, enabled: bool}>}>
     */
    public static function recorders(): array
    {
        $rows = [];

        foreach (config('monitor.recorders', []) as $class => $options) {
            $name = class_basename($class);

            $details = [];
            foreach (self::RECORDER_DETAILS[$name] ?? [] as $key => $labelKey) {
                $details[] = [
                    'key' => $key,
                    'label' => __($labelKey),
                    'enabled' => (bool) ($options['details'][$key] ?? false),
                    // Body cap shown under the toggle, only for the recorder that has one.
                    'bodySize' => $key === 'record_response_body' ? self::splitBodyBytes((int) ($options['details']['max_body_bytes'] ?? 64 * 1024)) : null,
                ];
            }

            $rows[] = [
                'name' => $name,
                'icon' => self::RECORDER_ICONS[$name] ?? Icons::BELL_ALERT,
                'enabled' => (bool) ($options['enabled'] ?? true),
                'details' => $details,
            ];
        }

        return $rows;
    }

    /**
     * $bytes as the largest whole unit that divides it evenly, so the form
     * round-trips any byte count exactly (e.g. 65536 => 64 KB, 1500 => 1500 B).
     *
     * @return array{size: int, unit: string}
     */
    public static function splitBodyBytes(int $bytes): array
    {
        foreach (array_reverse(self::BODY_SIZE_UNITS, true) as $unit => $multiplier) {
            if ($bytes >= $multiplier && $bytes % $multiplier === 0) {
                return ['size' => intdiv($bytes, $multiplier), 'unit' => $unit];
            }
        }

        return ['size' => max(1, $bytes), 'unit' => 'B'];
    }

    /** Inverse of splitBodyBytes(); an unknown unit counts as bytes. */
    public static function joinBodyBytes(int $size, string $unit): int
    {
        return $size * (self::BODY_SIZE_UNITS[$unit] ?? 1);
    }

    /** The Recorders section's single performance notice, naming every HEAVY_RECORDERS entry. */
    public static function recordersWarning(): string
    {
        return __('monitor::messages.settings.recorders_heavy_warning', [
            'names' => implode(', ', self::HEAVY_RECORDERS),
        ]);
    }

    /**
     * recorders() split into $columns groups, each rendered as its own stack so
     * a tall row never stretches its neighbour.
     *
     * @return list<list<array{name: string, icon: string, enabled: bool, details: array}>>
     */
    public static function recorderColumns(int $columns = 2): array
    {
        $recorders = self::recorders();

        return array_chunk($recorders, (int) ceil(count($recorders) / $columns));
    }

    /**
     * Each recorder's detail-option keys, for the settings form to validate against.
     *
     * @return array<string, list<string>>
     */
    public static function recorderDetailKeys(): array
    {
        return array_map(array_keys(...), self::RECORDER_DETAILS);
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
     * The stored overrides ([] when none). Stored as a PHP file returning an
     * array, like `config:cache`, so opcache serves the read on every request.
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

        $data = require $path;

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
        return app()->bootstrapPath('cache/monitor-settings.php');
    }

    /** The settings file, relative to the app root — for re-enable instructions. */
    public static function relativePath(): string
    {
        $path = static::path();
        $base = base_path().DIRECTORY_SEPARATOR;

        return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
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

        // Temp file + rename(), so a concurrent require() never sees a half-written file.
        $tmp = $path.'.'.uniqid('', true).'.tmp';
        file_put_contents($tmp, '<?php'.PHP_EOL.PHP_EOL.'return '.var_export($values, true).';'.PHP_EOL, LOCK_EX);
        rename($tmp, $path);

        // The redirect after save lands within opcache.revalidate_freq, which
        // would still serve the old bytecode.
        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($path, true);
        }

        static::$cache = $values;
    }
}
