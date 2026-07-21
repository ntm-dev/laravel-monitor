<?php

namespace LaravelMonitor\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use LaravelMonitor\Models\MonitorUser;
use LaravelMonitor\Support\Preferences;
use LaravelMonitor\Support\Settings;

/**
 * Persists dashboard settings from the single settings form:
 *  - per-viewer display preferences (theme/language/timezone) into the
 *    {@see Preferences::COOKIE} cookie, and
 *  - app-wide Environment + Recorders overrides, stored server-side via
 *    {@see Settings} and layered over config/monitor.php.
 * {@see reset()} clears the app-wide overrides back to the config defaults.
 */
class SettingsController
{
    public function system(Request $request): RedirectResponse
    {
        abort_unless($request->user(MonitorUser::guardName())->canManageSettings(), 403);

        $validated = $request->validate([
            'theme' => ['required', 'string', 'in:'.implode(',', Preferences::THEMES)],
            'locale' => ['required', 'string', 'in:'.implode(',', Preferences::availableLocales())],
            'timezone' => ['required', 'string', 'in:'.implode(',', Preferences::timezones())],
            'enabled' => ['nullable', 'boolean'],
            'storage_driver' => ['required', 'string', Rule::in(Settings::storageDrivers())],
            'database_table' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9_.]+$/'],
            'dashboard_path' => ['required', 'string', 'max:255', 'regex:#^[A-Za-z0-9/_-]+$#'],
            'retention_hours' => ['required', 'integer', 'min:1', 'max:87600'],
            'refresh' => ['required', 'integer', 'min:1', 'max:3600'],
            'request_threshold' => ['required', 'integer', 'min:0', 'max:600000'],
            'job_threshold' => ['required', 'integer', 'min:0', 'max:600000'],
            'query_threshold' => ['required', 'integer', 'min:0', 'max:600000'],
            'outgoing_request_threshold' => ['required', 'integer', 'min:0', 'max:600000'],
            'period_labels' => ['required', 'array', 'min:1'],
            'period_labels.*' => ['nullable', 'string', 'max:50'],
            'period_hours' => ['required', 'array'],
            'period_hours.*' => ['nullable', 'integer', 'min:1', 'max:87600'],
            'recorders' => ['nullable', 'array'],
            'recorders.*' => ['in:1'],
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
            'storage_driver' => $validated['storage_driver'],
            'database_table' => $validated['database_table'],
            'dashboard_path' => $path,
            'retention_hours' => (int) $validated['retention_hours'],
            'refresh' => (int) $validated['refresh'],
            'request_threshold' => (int) $validated['request_threshold'],
            'job_threshold' => (int) $validated['job_threshold'],
            'query_threshold' => (int) $validated['query_threshold'],
            'outgoing_request_threshold' => (int) $validated['outgoing_request_threshold'],
            'periods' => $periods,
            'recorders' => $this->recorderToggles($request),
        ]);

        $cookie = cookie(
            name: Preferences::COOKIE,
            value: json_encode([
                'theme' => $validated['theme'],
                'locale' => $validated['locale'],
                'timezone' => $validated['timezone'],
            ]),
            minutes: 60 * 24 * 365,
        );

        // Redirect to the (possibly new) path so the dashboard never lands on a
        // stale URL after the prefix changes on the next boot.
        return $this->backTo($path, 'monitor.settings_saved')->withCookie($cookie);
    }

    public function reset(Request $request): RedirectResponse
    {
        abort_unless($request->user(MonitorUser::guardName())->canManageSettings(), 403);

        Settings::reset();

        // Path reverts to the config default — redirect there, not the override.
        return $this->backTo(Settings::defaultPath(), 'monitor.settings_reset');
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
     * Redirect to the settings tab at the given dashboard path. Built by hand
     * (not via the route name) because the prefix may change on the next boot.
     */
    protected function backTo(string $path, string $flag): RedirectResponse
    {
        return redirect('/'.trim($path, '/').'/settings')->with($flag, true);
    }
}
