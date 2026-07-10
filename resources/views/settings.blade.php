@php($recorders = config('monitor.recorders', []))
<div class="space-y-4">
    <x-monitor::section :icon="\LaravelMonitor\Support\Icons::SETTINGS" title="General">
        <x-monitor::card class="p-4">
            <div class="divide-y divide-neutral-100">
                @foreach ([
                    ['Recording', config('monitor.enabled') ? 'Enabled' : 'Disabled', config('monitor.enabled')],
                    ['Storage driver', config('monitor.storage.driver', 'database'), null],
                    ['Database table', config('monitor.storage.database.table', 'monitor_entries'), null],
                    ['Retention', config('monitor.retention.hours', 168).' hours', null],
                    ['Dashboard path', '/'.trim(config('monitor.path', 'monitor'), '/'), null],
                    ['Dashboard refresh', config('monitor.refresh', 10).'s', null],
                    ['Periods', implode(', ', array_keys(config('monitor.periods', []))), null],
                    ['Request threshold', config('monitor.thresholds.request', 1000).'ms', null],
                    ['Job threshold', config('monitor.thresholds.job', 1000).'ms', null],
                ] as [$settingLabel, $settingValue, $settingState])
                    <div class="flex items-center justify-between py-2.5 text-sm">
                        <span class="text-neutral-500">{{ $settingLabel }}</span>
                        <span class="flex items-center gap-2 font-mono text-xs text-neutral-700">
                            @if ($settingState !== null)
                                <span class="h-1.5 w-1.5 rounded-full {{ $settingState ? 'bg-emerald-500' : 'bg-neutral-300' }}"></span>
                            @endif
                            {{ $settingValue }}
                        </span>
                    </div>
                @endforeach
            </div>
        </x-monitor::card>
    </x-monitor::section>

    <x-monitor::section :icon="\LaravelMonitor\Support\Icons::BELL_ALERT" title="Recorders">
        <x-monitor::card class="p-4">
            <div class="divide-y divide-neutral-100">
                @foreach ($recorders as $recorder => $options)
                    <div class="flex items-center justify-between py-2.5 text-sm">
                        <span class="font-mono text-xs text-neutral-700">{{ class_basename($recorder) }}</span>
                        <span class="flex items-center gap-2 font-mono text-xs {{ ($options['enabled'] ?? true) ? 'text-emerald-600' : 'text-neutral-400' }}">
                            <span class="h-1.5 w-1.5 rounded-full {{ ($options['enabled'] ?? true) ? 'bg-emerald-500' : 'bg-neutral-300' }}"></span>
                            {{ ($options['enabled'] ?? true) ? 'Enabled' : 'Disabled' }}
                        </span>
                    </div>
                @endforeach
            </div>
        </x-monitor::card>
    </x-monitor::section>
</div>
