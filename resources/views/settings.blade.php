{{-- Read-only settings overview. Rows are prepared by
     Http\Controllers\DashboardController::settings(). --}}
<div class="space-y-4">
    <x-monitor::section :icon="\LaravelMonitor\Support\Icons::SETTINGS" title="General">
        <x-monitor::card class="p-4">
            <div class="divide-y divide-neutral-100">
                @foreach ($settings['general'] as [$settingLabel, $settingValue, $settingState])
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
                @foreach ($settings['recorders'] as $recorder)
                    <div class="flex items-center justify-between py-2.5 text-sm">
                        <span class="font-mono text-xs text-neutral-700">{{ $recorder['name'] }}</span>
                        <span class="flex items-center gap-2 font-mono text-xs {{ $recorder['enabled'] ? 'text-emerald-600' : 'text-neutral-400' }}">
                            <span class="h-1.5 w-1.5 rounded-full {{ $recorder['enabled'] ? 'bg-emerald-500' : 'bg-neutral-300' }}"></span>
                            {{ $recorder['enabled'] ? 'Enabled' : 'Disabled' }}
                        </span>
                    </div>
                @endforeach
            </div>
        </x-monitor::card>
    </x-monitor::section>
</div>
