<?php

namespace LaravelMonitor\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use LaravelMonitor\Models\MonitorUser;
use LaravelMonitor\Support\Preferences;
use LaravelMonitor\Support\Settings;

/**
 * Saves the Settings form: personal preferences go to the {@see Preferences::COOKIE}
 * cookie for any user; app-wide overrides go to {@see Settings} and require
 * canManageSettings() (other users' forms don't submit those fields).
 */
class SettingsController
{
    public function system(Request $request): RedirectResponse|Response
    {
        $preferences = $request->validate([
            'theme' => ['required', 'string', 'in:'.implode(',', Preferences::THEMES)],
            'locale' => ['required', 'string', 'in:'.implode(',', Preferences::availableLocales())],
            'timezone' => ['required', 'string', 'in:'.implode(',', Preferences::timezones())],
        ]);

        $cookie = cookie(
            name: Preferences::COOKIE,
            value: json_encode($preferences),
            minutes: 60 * 24 * 365,
        );

        $path = trim((string) config('monitor.path', 'monitor'), '/');

        if (! $request->user(MonitorUser::guardName())->canManageSettings()) {
            return $this->backTo($path, 'success', __('monitor::messages.settings.settings_saved'))->withCookie($cookie);
        }

        $validated = $request->validate([
            'enabled' => ['nullable', 'boolean'],
            'disable_confirmation' => ['nullable', 'string', 'max:50'],
            'table_prefix' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9_.]+$/'],
            'dashboard_path' => ['required', 'string', 'max:255', 'regex:#^[A-Za-z0-9/_-]+$#'],
            'retention_hours' => ['required', 'integer', 'min:1', 'max:87600'],
            'refresh' => ['required', 'integer', 'min:1', 'max:3600'],
            'request_threshold' => ['required', 'integer', 'min:0', 'max:600000'],
            'job_threshold' => ['required', 'integer', 'min:0', 'max:600000'],
            'query_threshold' => ['required', 'integer', 'min:0', 'max:600000'],
            'outgoing_request_threshold' => ['required', 'integer', 'min:0', 'max:600000'],
            'request_body_max_size' => ['required', 'integer', 'min:1'],
            'request_body_max_unit' => ['required', 'in:'.implode(',', array_keys(Settings::BODY_SIZE_UNITS))],
            'aggregate_cache_enabled' => ['nullable', 'boolean'],
            'aggregate_cache_store' => ['nullable', 'string', 'max:255', 'in:'.implode(',', ['', ...Settings::aggregateCacheStores()])],
            'aggregate_cache_mode' => ['required', 'in:'.implode(',', Settings::AGGREGATE_CACHE_MODES)],
            'aggregate_cache_options' => ['nullable', 'array'],
            'aggregate_cache_options.*' => ['nullable', 'string', 'max:1000'],
            'period_labels' => ['required', 'array', 'min:1'],
            'period_labels.*' => ['nullable', 'string', 'max:50'],
            'period_hours' => ['required', 'array'],
            'period_hours.*' => ['nullable', 'integer', 'min:1', 'max:87600'],
            'recorders' => ['nullable', 'array'],
            'recorders.*' => ['in:1'],
            'recorder_details' => ['nullable', 'array'],
            'recorder_details.*' => ['array'],
            'recorder_details.*.*' => ['in:1'],
        ]);

        $disabling = ! $request->boolean('enabled');

        if ($disabling && trim((string) ($validated['disable_confirmation'] ?? '')) !== Settings::DISABLE_CONFIRMATION) {
            throw ValidationException::withMessages([
                'disable_confirmation' => __('monitor::messages.settings.disable_confirmation_invalid', ['phrase' => Settings::DISABLE_CONFIRMATION]),
            ]);
        }

        $periods = $this->buildPeriods($validated['period_labels'], $validated['period_hours']);

        if ($periods === []) {
            throw ValidationException::withMessages([
                'period_labels' => __('monitor::messages.settings.periods_required'),
            ]);
        }

        $bodyBytes = Settings::joinBodyBytes((int) $validated['request_body_max_size'], $validated['request_body_max_unit']);

        if ($bodyBytes > Settings::MAX_BODY_BYTES) {
            ['size' => $size, 'unit' => $unit] = Settings::splitBodyBytes(Settings::MAX_BODY_BYTES);

            throw ValidationException::withMessages([
                'request_body_max_size' => __('monitor::messages.settings.body_size_too_large', ['max' => "{$size} {$unit}"]),
            ]);
        }

        $path = trim($validated['dashboard_path'], '/');

        Settings::save([
            'enabled' => ! $disabling,
            'table_prefix' => $validated['table_prefix'],
            'dashboard_path' => $path,
            'retention_hours' => (int) $validated['retention_hours'],
            'refresh' => (int) $validated['refresh'],
            'request_threshold' => (int) $validated['request_threshold'],
            'job_threshold' => (int) $validated['job_threshold'],
            'query_threshold' => (int) $validated['query_threshold'],
            'outgoing_request_threshold' => (int) $validated['outgoing_request_threshold'],
            'request_body_max_bytes' => $bodyBytes,
            'aggregate_cache_enabled' => $request->boolean('aggregate_cache_enabled'),
            'aggregate_cache_store' => $validated['aggregate_cache_store'] ?? '',
            'aggregate_cache_mode' => $validated['aggregate_cache_mode'],
            // Only "custom" submits options; the other modes keep what was saved before.
            'aggregate_cache_options' => $validated['aggregate_cache_mode'] === 'custom'
                ? $this->aggregateCacheOptions($request, $validated['aggregate_cache_store'] ?? '')
                : (array) (Settings::all()['aggregate_cache_options'] ?? []),
            'periods' => $periods,
            'recorders' => $this->recorderToggles($request),
            'recorder_details' => $this->recorderDetailToggles($request),
        ]);

        // Rendered in place: from the next request on, no dashboard route exists to redirect to.
        if ($disabling) {
            return response()->view('monitor::disabled', ['settingsFile' => Settings::relativePath()])->withCookie($cookie);
        }

        // Redirect to the possibly new path; the prefix changes on the next boot.
        return $this->backTo($path, 'success', __('monitor::messages.settings.settings_saved'))->withCookie($cookie);
    }

    public function reset(Request $request): RedirectResponse
    {
        abort_unless($request->user(MonitorUser::guardName())->canManageSettings(), 403);

        Settings::reset();

        // Path reverts to the config default — redirect there, not the override.
        return $this->backTo(Settings::defaultPath(), 'warning', __('monitor::messages.settings.settings_reset'));
    }

    /**
     * Label => hours from the parallel row arrays, in row order, skipping incomplete rows.
     *
     * @param  array<int, string|null>  $labels
     * @param  array<int, int|string|null>  $hours
     * @return array<string, int>
     */
    protected function buildPeriods(array $labels, array $hours): array
    {
        $periods = [];

        foreach ($labels as $i => $label) {
            $label = is_string($label) ? trim($label) : '';
            $value = (int) ($hours[$i] ?? 0);

            if ($label !== '' && $value > 0) {
                $periods[$label] = $value;
            }
        }

        return $periods;
    }

    /**
     * Every known recorder => whether its checkbox was submitted.
     *
     * @return array<string, bool>
     */
    protected function recorderToggles(Request $request): array
    {
        $submitted = (array) $request->input('recorders', []);
        $toggles = [];

        foreach (array_keys(Settings::recorderClasses()) as $name) {
            $toggles[$name] = array_key_exists($name, $submitted);
        }

        return $toggles;
    }

    /**
     * Detail toggles limited to Settings::recorderDetailKeys(), true when submitted.
     *
     * @return array<string, array<string, bool>>
     */
    protected function recorderDetailToggles(Request $request): array
    {
        $submitted = (array) $request->input('recorder_details', []);
        $toggles = [];

        foreach (Settings::recorderDetailKeys() as $name => $keys) {
            $toggles[$name] = [];

            foreach ($keys as $key) {
                $toggles[$name][$key] = array_key_exists($key, $submitted[$name] ?? []);
            }
        }

        return $toggles;
    }

    /**
     * Submitted driver options, limited to Settings::aggregateCacheOptionFields() for $store.
     *
     * @return array<string, string>
     */
    protected function aggregateCacheOptions(Request $request, string $store): array
    {
        $allowed = Settings::aggregateCacheOptionFields($store);
        $submitted = (array) $request->input('aggregate_cache_options', []);

        return array_filter(
            array_intersect_key($submitted, array_flip($allowed)),
            fn ($value, $field) => $value !== null && $value !== ''
                && ($field !== 'connection' || in_array($value, Settings::databaseConnections(), true)),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * Redirect to settings under $path (not a route name: the prefix may change)
     * with a toast for components/toast-container.blade.php.
     */
    protected function backTo(string $path, string $level, string $message): RedirectResponse
    {
        return redirect('/'.trim($path, '/').'/settings')->with('monitor.toast', ['level' => $level, 'message' => $message]);
    }
}
