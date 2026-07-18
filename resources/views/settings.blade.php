{{-- Settings page — one form saves everything:
     - per-viewer display preferences (theme/language/timezone) → cookie, and
     - app-wide Environment + Recorders overrides over config/monitor.php,
       persisted server-side via Support\Settings (a saved value wins; anything
       left untouched keeps following the config file).
     Data prepared by Http\Controllers\DashboardController. --}}
@php
    $rowClass = 'flex items-center justify-between gap-4 py-2.5';
    $labelClass = 'text-sm text-neutral-700 dark:text-neutral-300';
    $fieldClass =
        'w-44 rounded-md border border-neutral-200 bg-white px-2 py-1.5 text-xs text-neutral-900 focus:border-blue-500 focus:outline-none dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-100';
    $numClass =
        'w-24 rounded-md border border-neutral-200 bg-white px-2 py-1.5 text-right font-mono text-xs text-neutral-900 focus:border-blue-500 focus:outline-none dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-100';
    $periodInput =
        'rounded-md border border-neutral-200 bg-white px-2 py-1.5 font-mono text-xs text-neutral-900 focus:border-blue-500 focus:outline-none dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-100';
    $periodItems = collect($system['periods'])
        ->map(fn($hours, $label) => ['label' => (string) $label, 'hours' => $hours])
        ->values();
@endphp
<div class="mx-auto max-w-5xl space-y-4">

    @if (session('monitor.settings_saved'))
        <x-monitor::settings-flash>{{ __('monitor::messages.settings.settings_saved') }}</x-monitor::settings-flash>
    @elseif (session('monitor.settings_reset'))
        <x-monitor::settings-flash>{{ __('monitor::messages.settings.settings_reset') }}</x-monitor::settings-flash>
    @endif

    @if ($errors->any())
        <div
            class="rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-xs text-rose-700 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-400">
            <ul class="list-inside list-disc space-y-0.5">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('monitor.settings.system') }}" x-data="{ theme: '{{ $prefs['theme'] }}', recordingEnabled: @js($system['enabled']) }" class="space-y-4">
        @csrf

        {{-- Per-viewer preferences (cookie) --}}
        <x-monitor::section :icon="\LaravelMonitor\Support\Icons::PREFERENCES" icon-view-box="0 0 76 76" icon-fill="currentColor" title="{{ __('monitor::messages.settings.preferences') }}" class="group" x-data="{ open: true }" :collapsible="true">
            <x-slot:actions>
                <x-monitor::settings-section-toggle/>
            </x-slot:actions>
            <div x-show="open" x-cloak x-transition:enter="transition ease-out duration-200"
                x-transition:enter-start="opacity-0 -translate-y-2" x-transition:enter-end="opacity-100 translate-y-0"
                x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100 translate-y-0"
                x-transition:leave-end="opacity-0 -translate-y-2">
            <x-monitor::card class="p-4">
                <p class="mb-4 text-xs text-neutral-500 dark:text-neutral-400">
                    {{ __('monitor::messages.settings.preferences_hint') }}</p>

                <div class="space-y-5">
                    <div>
                        <label
                            class="mb-1.5 block {{ $labelClass }}">{{ __('monitor::messages.settings.theme') }}</label>
                        <div
                            class="grid grid-cols-3 gap-0.5 rounded-lg border border-neutral-200 bg-neutral-100 p-0.5 dark:border-neutral-700 dark:bg-neutral-800">
                            @foreach ([
        'light' => [__('monitor::messages.settings.theme_light'), \LaravelMonitor\Support\Icons::SUN],
        'dark' => [__('monitor::messages.settings.theme_dark'), \LaravelMonitor\Support\Icons::MOON],
        'system' => [__('monitor::messages.settings.theme_system'), \LaravelMonitor\Support\Icons::COMPUTER],
    ] as $value => [$themeLabel, $themeIcon])
                                <label
                                    @click="theme = '{{ $value }}'; window.monitorApplyTheme && window.monitorApplyTheme('{{ $value }}')"
                                    class="flex cursor-pointer items-center justify-center gap-1.5 rounded-md px-2 py-1.5 text-xs font-medium"
                                    :class="theme === '{{ $value }}'
                                        ?
                                        'bg-white text-neutral-900 shadow-sm dark:bg-neutral-700 dark:text-neutral-100' :
                                        'text-neutral-500 hover:text-neutral-900 dark:text-neutral-400 dark:hover:text-neutral-100'">
                                    <input type="radio" name="theme" value="{{ $value }}" class="sr-only"
                                        @checked($prefs['theme'] === $value)>
                                    <x-monitor::icon :path="$themeIcon" class="h-4 w-4" />
                                    {{ $themeLabel }}
                                </label>
                            @endforeach
                        </div>
                    </div>

                    <div>
                        <label for="monitor-locale"
                            class="mb-1.5 block {{ $labelClass }}">{{ __('monitor::messages.settings.language') }}</label>
                        <select id="monitor-locale" name="locale"
                            class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-900 focus:border-blue-500 focus:outline-none dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-100">
                            @foreach ($localeOptions as $code => $name)
                                <option value="{{ $code }}" @selected($prefs['locale'] === $code)>{{ $name }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div x-data="monitorTimezonePicker(@js($timezoneOptions), @js($prefs['timezone']))" class="relative">
                        <input type="hidden" name="timezone" :value="selected">
                        <div class="mb-1.5 flex items-center justify-between">
                            <label
                                class="block {{ $labelClass }}">{{ __('monitor::messages.settings.timezone') }}</label>
                            <button type="button" @click="useBrowser()"
                                class="text-xs text-blue-600 hover:underline dark:text-blue-400">{{ __('monitor::messages.settings.use_browser_timezone') }}</button>
                        </div>
                        <button type="button" @click="toggle()"
                            class="flex w-full items-center justify-between gap-2 rounded-md border border-neutral-200 bg-white px-3 py-2 text-left text-sm text-neutral-900 focus:border-blue-500 focus:outline-none dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-100">
                            <span class="truncate" x-text="label(selected)"></span>
                            <span class="shrink-0 font-mono text-xs text-neutral-400 dark:text-neutral-500"
                                x-text="timeIn(selected)"></span>
                        </button>
                        <div x-show="open" x-cloak @click.outside="open = false"
                            class="absolute z-20 mt-1 w-full overflow-hidden rounded-md border border-neutral-200 bg-white shadow-lg dark:border-neutral-700 dark:bg-neutral-800">
                            <input x-ref="q" x-model="query" type="text"
                                placeholder="{{ __('monitor::messages.settings.tz_search') }}"
                                class="w-full border-b border-neutral-100 bg-transparent px-3 py-2 text-sm text-neutral-900 focus:outline-none dark:border-neutral-700 dark:text-neutral-100">
                            <ul class="max-h-60 overflow-auto py-1">
                                <template x-for="opt in filtered()" :key="opt.value">
                                    <li @click="choose(opt.value)"
                                        class="flex cursor-pointer items-center justify-between gap-2 px-3 py-1.5 text-xs hover:bg-neutral-100 dark:hover:bg-neutral-700"
                                        :class="opt.value === selected ? 'bg-neutral-100 dark:bg-neutral-700' : ''">
                                        <span class="truncate text-neutral-700 dark:text-neutral-200">
                                            <span x-text="opt.name"></span>
                                            <span class="text-neutral-400 dark:text-neutral-500"
                                                x-text="'(' + opt.offset + ')'"></span>
                                        </span>
                                        <span class="shrink-0 font-mono text-neutral-400 dark:text-neutral-500"
                                            x-text="timeIn(opt.value)"></span>
                                    </li>
                                </template>
                                <li x-show="filtered().length === 0"
                                    class="px-3 py-2 text-xs text-neutral-400 dark:text-neutral-500">
                                    {{ __('monitor::messages.settings.tz_no_match') }}</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </x-monitor::card>
            </div>
        </x-monitor::section>

        {{-- App-wide environment (config overrides) --}}
        <x-monitor::section :icon="\LaravelMonitor\Support\Icons::SETTINGS" title="{{ __('monitor::messages.settings.environment') }}" class="group" x-data="{ open: false }" :collapsible="true">
            <x-slot:actions>
                <x-monitor::settings-section-toggle/>
            </x-slot:actions>
            <div x-show="open" x-cloak x-transition:enter="transition ease-out duration-200"
                x-transition:enter-start="opacity-0 -translate-y-2" x-transition:enter-end="opacity-100 translate-y-0"
                x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100 translate-y-0"
                x-transition:leave-end="opacity-0 -translate-y-2">
            <x-monitor::card class="p-4">
                <p class="mb-3 text-xs text-neutral-500 dark:text-neutral-400">
                    {{ __('monitor::messages.settings.environment_editable_hint') }}</p>
                <div class="divide-y divide-neutral-100 dark:divide-neutral-800">

                    <div class="{{ $rowClass }}">
                        <span class="{{ $labelClass }}">{{ __('monitor::messages.settings.recording') }}</span>
                        <x-monitor::toggle name="enabled" :checked="$system['enabled']" x-model="recordingEnabled" />
                    </div>

                    {{-- Recorders: shown right below the Recording toggle, only while recording is enabled --}}
                    <div x-show="recordingEnabled" x-cloak x-transition:enter="transition ease-out duration-200"
                        x-transition:enter-start="opacity-0 -translate-y-2" x-transition:enter-end="opacity-100 translate-y-0"
                        x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100 translate-y-0"
                        x-transition:leave-end="opacity-0 -translate-y-2" class="py-2.5">
                        <label class="mb-1.5 block {{ $labelClass }}">{{ __('monitor::messages.settings.recorders') }}</label>
                        <p class="mb-2 text-xs text-neutral-500 dark:text-neutral-400">
                            {{ __('monitor::messages.settings.recorders_hint') }}</p>
                        <div class="grid gap-x-8 sm:grid-cols-2">
                            @foreach ($system['recorders'] as $recorder)
                                <div
                                    class="flex items-center justify-between gap-4 border-b border-neutral-100 py-2.5 dark:border-neutral-800">
                                    <span class="flex min-w-0 items-center gap-2 font-mono text-xs text-neutral-700 dark:text-neutral-300">
                                        <x-monitor::icon :path="$recorder['icon']" class="h-4 w-4 shrink-0 text-neutral-400 dark:text-neutral-500"/>
                                        <span class="truncate">{{ $recorder['name'] }}</span>
                                    </span>
                                    <x-monitor::toggle name="recorders[{{ $recorder['name'] }}]" :checked="$recorder['enabled']" />
                                </div>
                            @endforeach
                        </div>
                    </div>

                    <div class="{{ $rowClass }}">
                        <label for="s-driver"
                            class="{{ $labelClass }}">{{ __('monitor::messages.settings.storage_driver') }}</label>
                        <select id="s-driver" name="storage_driver" class="{{ $fieldClass }}">
                            @foreach ($storageDrivers as $driver)
                                <option value="{{ $driver }}" @selected($system['storage_driver'] === $driver)>{{ $driver }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="{{ $rowClass }}">
                        <label for="s-table"
                            class="{{ $labelClass }}">{{ __('monitor::messages.settings.database_table') }}</label>
                        <input id="s-table" name="database_table"
                            value="{{ old('database_table', $system['database_table']) }}"
                            class="{{ $fieldClass }} font-mono">
                    </div>

                    <div class="{{ $rowClass }}">
                        <label for="s-path"
                            class="{{ $labelClass }}">{{ __('monitor::messages.settings.dashboard_path') }}</label>
                        <input id="s-path" name="dashboard_path"
                            value="{{ old('dashboard_path', $system['dashboard_path']) }}"
                            class="{{ $fieldClass }} font-mono">
                    </div>

                    <div class="{{ $rowClass }}">
                        <label for="s-retention"
                            class="{{ $labelClass }}">{{ __('monitor::messages.settings.retention') }}</label>
                        <div class="flex items-center gap-1.5">
                            <input id="s-retention" name="retention_hours" type="number" min="1"
                                value="{{ old('retention_hours', $system['retention_hours']) }}"
                                class="{{ $numClass }}">
                            <span class="text-xs text-neutral-400 dark:text-neutral-500">h</span>
                        </div>
                    </div>

                    <div class="{{ $rowClass }}">
                        <label for="s-refresh"
                            class="{{ $labelClass }}">{{ __('monitor::messages.settings.dashboard_refresh') }}</label>
                        <div class="flex items-center gap-1.5">
                            <input id="s-refresh" name="refresh" type="number" min="1"
                                value="{{ old('refresh', $system['refresh']) }}" class="{{ $numClass }}">
                            <span class="text-xs text-neutral-400 dark:text-neutral-500">s</span>
                        </div>
                    </div>

                    <div class="py-2.5" x-data="{ items: @js($periodItems) }">
                        <label
                            class="mb-1.5 block {{ $labelClass }}">{{ __('monitor::messages.settings.periods') }}</label>
                        <div class="space-y-2">
                            <template x-for="(item, index) in items" :key="index">
                                <div class="flex items-center gap-2">
                                    <input type="text" name="period_labels[]" x-model="item.label"
                                        placeholder="{{ __('monitor::messages.settings.period_label') }}"
                                        class="flex-1 {{ $periodInput }}">
                                    <input type="number" min="1" name="period_hours[]" x-model="item.hours"
                                        placeholder="{{ __('monitor::messages.settings.period_hours') }}"
                                        class="w-24 {{ $periodInput }} text-right">
                                    <button type="button" @click="items.splice(index, 1)"
                                        title="{{ __('monitor::messages.settings.remove') }}"
                                        class="flex h-7 w-7 shrink-0 items-center justify-center rounded-md border border-neutral-200 text-neutral-400 hover:border-rose-300 hover:text-rose-600 dark:border-neutral-700 dark:hover:border-rose-500/40 dark:hover:text-rose-400">
                                        <x-monitor::icon :path="\LaravelMonitor\Support\Icons::CLOSE" :stroke="2" class="h-3.5 w-3.5" />
                                    </button>
                                </div>
                            </template>
                        </div>
                        <button type="button" @click="items.push({ label: '', hours: '' })"
                            class="mt-2 inline-flex items-center gap-1 text-xs font-medium text-blue-600 hover:underline dark:text-blue-400">
                            <x-monitor::icon :path="\LaravelMonitor\Support\Icons::PLUS" :stroke="2"
                                class="h-3.5 w-3.5" />{{ __('monitor::messages.settings.add_period') }}
                        </button>
                        <p class="mt-1.5 text-xs text-neutral-400 dark:text-neutral-500">
                            {{ __('monitor::messages.settings.periods_help') }}</p>
                    </div>
                </div>
                <p class="mt-3 text-[11px] text-neutral-400 dark:text-neutral-500">
                    {{ __('monitor::messages.settings.storage_note') }}</p>
            </x-monitor::card>
            </div>
        </x-monitor::section>

        {{-- App-wide threshold (config overrides) --}}
        <x-monitor::section :icon="\LaravelMonitor\Support\Icons::ANOMALY" icon-view-box="0 0 512 512" icon-fill="currentColor" icon-transform="translate(42.666667, 42.666667)" title="{{ __('monitor::messages.settings.threshold') }}" class="group" x-data="{ open: false }" :collapsible="true">
            <x-slot:actions>
                <x-monitor::settings-section-toggle/>
            </x-slot:actions>
            <div x-show="open" x-cloak x-transition:enter="transition ease-out duration-200"
                x-transition:enter-start="opacity-0 -translate-y-2" x-transition:enter-end="opacity-100 translate-y-0"
                x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100 translate-y-0"
                x-transition:leave-end="opacity-0 -translate-y-2">
            <x-monitor::card class="p-4">
                <p class="mb-3 text-xs text-neutral-500 dark:text-neutral-400">
                    {{ __('monitor::messages.settings.environment_editable_hint') }}</p>
                <div class="divide-y divide-neutral-100 dark:divide-neutral-800">

                    <div class="{{ $rowClass }}">
                        <label for="s-req"
                            class="{{ $labelClass }}">{{ __('monitor::messages.settings.request_threshold') }}</label>
                        <div class="flex items-center gap-1.5">
                            <input id="s-req" name="request_threshold" type="number" min="0"
                                value="{{ old('request_threshold', $system['request_threshold']) }}"
                                class="{{ $numClass }}">
                            <span class="text-xs text-neutral-400 dark:text-neutral-500">ms</span>
                        </div>
                    </div>

                    <div class="{{ $rowClass }}">
                        <label for="s-job"
                            class="{{ $labelClass }}">{{ __('monitor::messages.settings.job_threshold') }}</label>
                        <div class="flex items-center gap-1.5">
                            <input id="s-job" name="job_threshold" type="number" min="0"
                                value="{{ old('job_threshold', $system['job_threshold']) }}"
                                class="{{ $numClass }}">
                            <span class="text-xs text-neutral-400 dark:text-neutral-500">ms</span>
                        </div>
                    </div>
                    <div class="{{ $rowClass }}">
                        <label for="s-query"
                            class="{{ $labelClass }}">{{ __('monitor::messages.settings.query_threshold') }}</label>
                        <div class="flex items-center gap-1.5">
                            <input id="s-query" name="query_threshold" type="number" min="0"
                                value="{{ old('query_threshold', $system['query_threshold']) }}"
                                class="{{ $numClass }}">
                            <span class="text-xs text-neutral-400 dark:text-neutral-500">ms</span>
                        </div>
                    </div>

                    <div class="{{ $rowClass }}">
                        <label for="s-outgoing"
                            class="{{ $labelClass }}">{{ __('monitor::messages.settings.outgoing_request_threshold') }}</label>
                        <div class="flex items-center gap-1.5">
                            <input id="s-outgoing" name="outgoing_request_threshold" type="number" min="0"
                                value="{{ old('outgoing_request_threshold', $system['outgoing_request_threshold']) }}"
                                class="{{ $numClass }}">
                            <span class="text-xs text-neutral-400 dark:text-neutral-500">ms</span>
                        </div>
                    </div>
                </div>
            </x-monitor::card>
            </div>
        </x-monitor::section>

        <div class="flex items-center justify-end gap-2">
            <button type="submit" formnovalidate formaction="{{ route('monitor.settings.reset') }}"
                class="rounded-md border border-neutral-200 px-4 py-2 text-sm font-medium text-neutral-600 hover:bg-neutral-100 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-800">{{ __('monitor::messages.settings.reset') }}</button>
            <button type="submit"
                class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-500">{{ __('monitor::messages.settings.save_system') }}</button>
        </div>
    </form>
</div>

{{-- Searchable timezone combobox: filter by name/offset, live current time per
     zone (computed client-side via Intl so it stays accurate). --}}
<script>
    window.monitorTimezonePicker = function(options, selected) {
        return {
            open: false,
            query: '',
            now: Date.now(),
            selected: selected,
            options: options,
            init() {
                setInterval(() => {
                    this.now = Date.now();
                }, 1000);
            },
            toggle() {
                this.open = !this.open;
                if (this.open) {
                    this.$nextTick(() => this.$refs.q && this.$refs.q.focus());
                }
            },
            filtered() {
                const q = this.query.trim().toLowerCase();
                const list = q ?
                    this.options.filter(o => o.name.toLowerCase().includes(q) || o.offset.toLowerCase().includes(
                    q)) :
                    this.options;
                return list.slice(0, 100);
            },
            label(value) {
                const o = this.options.find(x => x.value === value);
                return o ? o.name + ' (' + o.offset + ')' : value;
            },
            timeIn(value) {
                try {
                    return new Intl.DateTimeFormat('en-GB', {
                        timeZone: value,
                        hour: '2-digit',
                        minute: '2-digit',
                        second: '2-digit',
                    }).format(new Date(this.now));
                } catch (e) {
                    return '';
                }
            },
            choose(value) {
                this.selected = value;
                this.open = false;
                this.query = '';
            },
            useBrowser() {
                const tz = Intl.DateTimeFormat().resolvedOptions().timeZone;
                if (!tz) {
                    return;
                }
                // The browser may report a legacy CLDR alias (e.g. "Asia/Saigon")
                // while our options use IANA canonical ids ("Asia/Ho_Chi_Minh").
                // Intl normalises any id passed as timeZone to the same alias
                // form, so compare options through that normalisation.
                const canonical = (value) => {
                    try {
                        return Intl.DateTimeFormat('en', {
                            timeZone: value
                        }).resolvedOptions().timeZone;
                    } catch (e) {
                        return value;
                    }
                };
                const match = this.options.find(o => o.value === tz) ||
                    this.options.find(o => canonical(o.value) === tz);
                if (match) {
                    this.selected = match.value;
                }
            },
        };
    };
</script>
