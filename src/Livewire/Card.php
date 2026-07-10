<?php

namespace LaravelMonitor\Livewire;

use Carbon\CarbonImmutable;
use LaravelMonitor\Contracts\Storage;
use Livewire\Component;

abstract class Card extends Component
{
    public string $period = '1h';

    public function mount(?string $period = null): void
    {
        $period ??= request('period', '1h');

        $this->period = in_array($period, ['1h', '6h', '24h', '7d'], true) ? $period : '1h';
    }

    protected function since(): CarbonImmutable
    {
        return match ($this->period) {
            '6h' => CarbonImmutable::now()->subHours(6),
            '24h' => CarbonImmutable::now()->subHours(24),
            '7d' => CarbonImmutable::now()->subDays(7),
            default => CarbonImmutable::now()->subHour(),
        };
    }

    protected function storage(): Storage
    {
        return app(Storage::class);
    }
}
