<?php

namespace LaravelMonitor\Support;

/**
 * Per-viewer dashboard preferences (theme, language, timezone).
 *
 * Persisted in a single JSON cookie so they survive across requests without a
 * database table, and are available to both the Blade shell and Livewire card
 * re-renders (which carry the same cookie). When a preference is unset we fall
 * back to the viewer's "local machine" hints — the request's preferred language
 * and the app timezone — mirroring what a fresh browser would report.
 */
class Preferences
{
    /** Cookie name holding the JSON-encoded preferences. */
    public const COOKIE = 'monitor_prefs';

    /** Selectable theme modes. "system" follows the OS light/dark setting. */
    public const THEMES = ['light', 'dark', 'system'];

    /**
     * Human-readable names for the locales shipped with the package.
     *
     * @var array<string, string>
     */
    public const LOCALE_NAMES = [
        'en' => 'English',
        'vi' => 'Tiếng Việt',
    ];

    /**
     * All resolved preferences, each falling back to a sensible default.
     *
     * @return array{theme: string, locale: string, timezone: string}
     */
    public static function all(): array
    {
        return [
            'theme' => static::theme(),
            'locale' => static::locale(),
            'timezone' => static::timezone(),
        ];
    }

    public static function theme(): string
    {
        $theme = static::stored()['theme'] ?? null;

        return in_array($theme, self::THEMES, true) ? $theme : 'system';
    }

    public static function locale(): string
    {
        $available = static::availableLocales();
        $stored = static::stored()['locale'] ?? null;

        if (is_string($stored) && in_array($stored, $available, true)) {
            return $stored;
        }

        // Unset: honour the browser's Accept-Language ("local machine"), then app default.
        $preferred = request()?->getPreferredLanguage($available);

        if (is_string($preferred) && in_array($preferred, $available, true)) {
            return $preferred;
        }

        $app = config('app.locale', 'en');

        return in_array($app, $available, true) ? $app : ($available[0] ?? 'en');
    }

    public static function timezone(): string
    {
        $stored = static::stored()['timezone'] ?? null;

        if (is_string($stored) && in_array($stored, timezone_identifiers_list(), true)) {
            return $stored;
        }

        return config('app.timezone') ?: 'UTC';
    }

    /**
     * Locale codes that ship with the package (one folder per locale).
     *
     * @return list<string>
     */
    public static function availableLocales(): array
    {
        $path = __DIR__.'/../../resources/lang';

        if (! is_dir($path)) {
            return ['en'];
        }

        $dirs = array_map('basename', glob($path.'/*', GLOB_ONLYDIR) ?: []);
        sort($dirs);

        return $dirs ?: ['en'];
    }

    /**
     * Available locales mapped to their display names for the settings picker.
     *
     * @return array<string, string>
     */
    public static function localeOptions(): array
    {
        $options = [];

        foreach (static::availableLocales() as $locale) {
            $options[$locale] = self::LOCALE_NAMES[$locale] ?? $locale;
        }

        return $options;
    }

    /**
     * All IANA timezone identifiers, for the settings picker.
     *
     * @return list<string>
     */
    public static function timezones(): array
    {
        return timezone_identifiers_list();
    }

    /**
     * Timezone options for the searchable picker: identifier plus its current
     * UTC offset (e.g. "UTC+7"), sorted west-to-east. Current-of-day time is
     * rendered client-side so it stays live.
     *
     * @return list<array{value: string, name: string, offset: string, minutes: int}>
     */
    public static function timezoneOptions(): array
    {
        $now = new \DateTime('now', new \DateTimeZone('UTC'));
        $options = [];

        foreach (timezone_identifiers_list() as $tz) {
            $seconds = (new \DateTimeZone($tz))->getOffset($now);

            $options[] = [
                'value' => $tz,
                'name' => str_replace('_', ' ', $tz),
                'offset' => static::formatOffset($seconds),
                'minutes' => intdiv($seconds, 60),
            ];
        }

        usort($options, fn ($a, $b) => [$a['minutes'], $a['name']] <=> [$b['minutes'], $b['name']]);

        return $options;
    }

    /** Format a UTC offset in seconds as "UTC+7" / "UTC+5:30" / "UTC-3". */
    protected static function formatOffset(int $seconds): string
    {
        $sign = $seconds < 0 ? '-' : '+';
        $abs = abs($seconds);
        $hours = intdiv($abs, 3600);
        $minutes = intdiv($abs % 3600, 60);

        return 'UTC'.$sign.$hours.($minutes > 0 ? ':'.str_pad((string) $minutes, 2, '0', STR_PAD_LEFT) : '');
    }

    /**
     * Decoded cookie payload, or an empty array when unset/malformed.
     *
     * @return array<string, mixed>
     */
    protected static function stored(): array
    {
        $raw = request()?->cookie(self::COOKIE);

        if (! is_string($raw) || $raw === '') {
            return [];
        }

        $data = json_decode($raw, true);

        return is_array($data) ? $data : [];
    }
}
