<?php

namespace LaravelMonitor\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use LaravelMonitor\Contracts\Storage;

class PruneCommand extends Command
{
    protected $signature = 'monitor:prune {--hours= : Prune entries older than this many hours}';

    protected $description = 'Delete monitor entries older than the retention period';

    public function handle(Storage $storage): int
    {
        $hours = (int) ($this->option('hours') ?? config('monitor.retention.hours', 168));

        $deleted = $storage->purge(CarbonImmutable::now()->subHours($hours));

        $this->info($deleted >= 0
            ? "Pruned {$deleted} entries older than {$hours} hours."
            : "Pruned entries older than {$hours} hours.");

        return self::SUCCESS;
    }
}
