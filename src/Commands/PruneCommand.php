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
        $before = CarbonImmutable::now()->subHours($hours);

        $deleted = $storage->purge($before);
        $expired = $storage->expireStaleIssues();

        $this->info($deleted >= 0
            ? "Pruned {$deleted} entries older than {$hours} hours."
            : "Pruned entries older than {$hours} hours.");

        if ($expired > 0) {
            $this->info("Deleted {$expired} open issue(s) with no remaining data.");
        }

        return self::SUCCESS;
    }
}
