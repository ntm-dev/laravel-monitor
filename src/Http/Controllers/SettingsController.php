<?php

namespace LaravelMonitor\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use LaravelMonitor\Models\MonitorUser;
use LaravelMonitor\Support\Preferences;
use LaravelMonitor\Support\Settings;

/**
 * Persists dashboard settings from the single Settings form:
 *  - Preferences (theme/language/timezone) → the {@see Preferences::COOKIE}
 *    cookie. Open to any signed-in monitor user — a personal display
 *    choice, not a team setting — so these fields are always processed.
 *  - Environment + Recorders overrides over config/monitor.php → stored
 *    server-side via {@see Settings}. Restricted to canManageSettings()
 *    (owner/admin): the form disables that whole fieldset for anyone else,
 *    so the browser never submits those fields for them, and {@see system()}
 *    stops right after the cookie in that case rather than validating
 *    fields that were never sent.
 * {@see reset()} clears the app-wide overrides back to the config defaults.
 */
class SettingsController
{
    public function system(Request $request): RedirectResponse
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
            'table_prefix' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9_.]+$/'],
            'dashboard_path' => ['required', 'string', 'max:255', 'regex:#^[A-Za-z0-9/_-]+$#'],
            'retention_hours' => ['required', 'integer', 'min:1', 'max:87600'],
            'refresh' => ['required', 'integer', 'min:1', 'max:3600'],
            'request_threshold' => ['required', 'integer', 'min:0', 'max:600000'],
            'job_threshold' => ['required', 'integer', 'min:0', 'max:600000'],
            'query_threshold' => ['required', 'integer', 'min:0', 'max:600000'],
            'outgoing_request_threshold' => ['required', 'integer', 'min:0', 'max:600000'],
            'aggregate_cache_enabled' => ['nullable', 'boolean'],
            'aggregate_cache_store' => ['nullable', 'string', 'max:255', 'in:'.implode(',', ['', ...Settings::aggregateCacheStores()])],
            'aggregate_cache_use_app_config' => ['nullable', 'boolean'],
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

        $periods = $this->buildPeriods($validated['period_labels'], $validated['period_hours']);

        if ($periods === []) {
            throw ValidationException::withMessages([
                'period_labels' => __('monitor::messages.settings.periods_required'),
            ]);
        }

        $path = trim($validated['dashboard_path'], '/');

        Settings::save([
            'enabled' => $request->boolean('enabled'),
            'table_prefix' => $validated['table_prefix'],
            'dashboard_path' => $path,
            'retention_hours' => (int) $validated['retention_hours'],
            'refresh' => (int) $validated['refresh'],
            'request_threshold' => (int) $validated['request_threshold'],
            'job_threshold' => (int) $validated['job_threshold'],
            'query_threshold' => (int) $validated['query_threshold'],
            'outgoing_request_threshold' => (int) $validated['outgoing_request_threshold'],
            'aggregate_cache_enabled' => $request->boolean('aggregate_cache_enabled'),
            'aggregate_cache_store' => $validated['aggregate_cache_store'] ?? '',
            'aggregate_cache_use_app_config' => $request->boolean('aggregate_cache_use_app_config'),
            'aggregate_cache_options' => $this->aggregateCacheOptions($request, $validated['aggregate_cache_store'] ?? ''),
            'periods' => $periods,
            'recorders' => $this->recorderToggles($request),
            'recorder_details' => $this->recorderDetailToggles($request),
        ]);

        // Redirect to the (possibly new) path so the dashboard never lands on a
        // stale URL after the prefix changes on the next boot.
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
     * Build the periods map from the parallel label/hours arrays submitted by
     * the repeatable rows, keeping the row order and skipping incomplete rows.
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
     * The full recorder-enabled map: every known recorder, true when its
     * checkbox was submitted.
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
     * Every recorder's detail-option toggles: only the keys Settings::
     * recorderDetailKeys() actually declares, true when that checkbox was
     * submitted.
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
     * Submitted driver options, narrowed to Settings::aggregateCacheOptionFields()
     * for the store actually being saved — anything else submitted (a
     * stale field left over from a previously-selected driver, or a
     * crafted key) is dropped rather than trusted onto disk.
     *
     * @return array<string, string>
     */
    protected function aggregateCacheOptions(Request $request, string $store): array
    {
        $allowed = Settings::aggregateCacheOptionFields($store);
        $submitted = (array) $request->input('aggregate_cache_options', []);

        return array_filter(
            array_intersect_key($submitted, array_flip($allowed)),
            fn ($value) => $value !== null && $value !== '',
        );
    }

    /**
     * Redirect to the settings tab at the given dashboard path (built by
     * hand, not via the route name, because the prefix may change on the
     * next boot), flashing a toast the shared toast-container picks up on
     * the page it lands on (see components/toast-container.blade.php).
     */
    protected function backTo(string $path, string $level, string $message): RedirectResponse
    {
        return redirect('/'.trim($path, '/').'/settings')->with('monitor.toast', ['level' => $level, 'message' => $message]);
    }
}
