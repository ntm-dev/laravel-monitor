<?php

namespace LaravelMonitor\Commands;

use Illuminate\Console\Command;
use LaravelMonitor\Support\Aggregator;

class AggregateCommand extends Command
{
    protected $signature = 'monitor:aggregate {--period= : Bucket width in seconds}';

    protected $description = 'Roll raw monitor entries up into monitor_aggregates buckets';

    public function handle(Aggregator $aggregator): int
    {
        $period = (int) ($this->option('period') ?? config('monitor.aggregates.period', 60));

        $processed = $aggregator->run($period);

        $this->info($processed > 0
            ? "Aggregated {$processed} bucket(s) of {$period}s."
            : 'No new buckets to aggregate.');

        return self::SUCCESS;
    }
}
