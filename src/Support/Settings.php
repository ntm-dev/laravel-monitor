<?php

namespace LaravelMonitor\Support;

use function array_chunk;
use function array_key_exists;
use function array_keys;
use function array_map;
use function array_values;
use function ceil;
use function count;
use function implode;
use function is_array;

/**
 * App-wide settings overrides for the values that config/monitor.php ships as
 * defaults (the Environment + Recorders sections of the dashboard).
 *
 * Unlike {@see Preferences} (per-viewer, cookie), these are shared by the whole
 * application and affect recording behaviour, so they are persisted server-side
 * as a PHP array-returning file — see {@see write()} for why. {@see apply()}
 * overlays whatever is stored onto the live
 * config at boot — a stored value wins, and anything not stored keeps its
 * config/monitor.php default ("nếu chưa cài đặt thì lấy mặc định").
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
    ];

    /**
     * Config path (leaf table name) => suffix appended to a stored prefix
     * override (see apply()) — mirrors every table config/monitor.php
     * itself derives from $tablePrefix there.
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
    ];

    /**
     * Every TABLE_SUFFIXES value, for the Settings form to preview the full
     * table name (prefix + suffix) live as the prefix field is typed into.
     *
     * @return list<string>
     */
    public static function tableSuffixes(): array
    {
        return array_values(self::TABLE_SUFFIXES);
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

        // One prefix override fans out to every table config/monitor.php
        // itself derives from $tablePrefix — see TABLE_SUFFIXES.
        if (array_key_exists('table_prefix', $stored)) {
            config(['monitor.table_prefix' => $stored['table_prefix']]);

            foreach (self::TABLE_SUFFIXES as $path => $suffix) {
                config([$path => $stored['table_prefix'].$suffix]);
            }
        }

        if (isset($stored['recorders']) && is_array($stored['recorders'])) {
            $classes = static::recorderClasses();

            foreach ($stored['recorders'] as $name => $enabled) {
                $name = self::LEGACY_RECORDER_ALIASES[$name] ?? $name;

                if (isset($classes[$name])) {
                    config(['monitor.recorders.'.$classes[$name].'.enabled' => (bool) $enabled]);
                }
            }
        }

        if (isset($stored['recorder_details']) && is_array($stored['recorder_details'])) {
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
            'recorders' => static::recorders(),
            'recorderColumns' => static::recorderColumns(),
            'recordersWarning' => static::recordersWarning(),
            'is_customized' => static::all() !== [],
        ];
    }

    /**
     * Old recorder basename => current one, so a settings file saved before
     * a class rename still resolves instead of silently losing the toggle.
     */
    protected const LEGACY_RECORDER_ALIASES = [
        'SlowQueries' => 'Queries',
    ];

    /**
     * Highest-volume recorders — default disabled outside local (see
     * config/monitor.php) and named in recordersWarning(), the one notice
     * at the top of the Settings form's Recorders section.
     */
    protected const HEAVY_RECORDERS = ['Queries', 'Models', 'CacheInteractions'];

    /**
     * Detail sub-options per recorder: basename => [config key => translated
     * label key]. Each one gets its own toggle nested under that recorder's
     * row in Settings, shown only while the recorder itself is enabled —
     * off by default outside local like HEAVY_RECORDERS, covered by the
     * same recordersWarning() notice rather than named individually.
     */
    protected const RECORDER_DETAILS = [
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
     * Recorder rows for the form: display name, icon, enabled state, and its
     * own nested detail toggles (see RECORDER_DETAILS). The performance cost
     * HEAVY_RECORDERS/RECORDER_DETAILS entries carry isn't flagged per row
     * any more — see recordersWarning() for the one consolidated notice.
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
     * One consolidated performance-cost notice for the top of the Settings
     * form's Recorders section, naming every HEAVY_RECORDERS entry, instead
     * of a warning line repeated under each individual heavy toggle. Detail
     * options (RECORDER_DETAILS) aren't named individually — always heavier
     * than their own recorder's base fields, so one blanket mention below
     * the recorder list covers them.
     */
    public static function recordersWarning(): string
    {
        return __('monitor::messages.settings.recorders_heavy_warning', [
            'names' => implode(', ', self::HEAVY_RECORDERS),
        ]);
    }

    /**
     * recorders() split into $columns evenly-sized groups, for the Settings
     * form's side-by-side layout — computed here rather than chunking a
     * collection in the Blade file, so a recorder whose detail toggles push
     * it taller never stretches its unrelated row-mate in a shared CSS grid
     * row (each column is its own independent flex stack instead).
     *
     * @return list<list<array{name: string, icon: string, enabled: bool, details: array}>>
     */
    public static function recorderColumns(int $columns = 2): array
    {
        $recorders = self::recorders();

        return array_chunk($recorders, (int) ceil(count($recorders) / $columns));
    }

    /**
     * Every recorder's own detail-option keys, for the settings form to
     * validate/persist against without reaching into RECORDER_DETAILS
     * directly.
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
     * The stored overrides, or an empty array when nothing has been saved.
     * Persisted as a plain PHP file returning an array — the same technique
     * `artisan config:cache` uses — instead of JSON, so a read is a
     * `require` that PHP's own opcache compiles once and reuses across
     * requests, rather than paying file_get_contents() + json_decode() on
     * every single request this is read on (recorders, the dashboard,
     * Settings::apply() at boot).
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

        // var_export(), not json_encode(): the file must be valid PHP
        // source (`<?php return [...];`) for all() to require() it — see
        // there for why. Written to a temp file and renamed into place so a
        // concurrent require() during the write never sees a half-written
        // file; rename() is atomic on the same filesystem.
        $tmp = $path.'.'.uniqid('', true).'.tmp';
        file_put_contents($tmp, '<?php'.PHP_EOL.PHP_EOL.'return '.var_export($values, true).';'.PHP_EOL, LOCK_EX);
        rename($tmp, $path);

        // The settings form redirects straight back to this same page, so
        // the very next require() of this file is almost always inside
        // opcache.revalidate_freq (2s default) of this write. Without an
        // explicit invalidation that reload can still serve the pre-save
        // bytecode, making the save look like it silently reverted until a
        // second save happens to land outside that window. Force just this
        // one path to recompile now instead of waiting on the timestamp
        // check.
        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($path, true);
        }

        static::$cache = $values;
    }
}
