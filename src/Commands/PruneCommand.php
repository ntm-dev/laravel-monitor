<?php

namespace LaravelMonitor\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use LaravelMonitor\Contracts\AggregateStorage;
use LaravelMonitor\Contracts\EntryWriter;
use LaravelMonitor\Contracts\IssueStorage;
use LaravelMonitor\Livewire\Concerns\SyncsOpenIssues;

class PruneCommand extends Command
{
    use SyncsOpenIssues;

    protected $signature = 'monitor:prune {--hours= : Prune entries older than this many hours}';

    protected $description = 'Delete monitor entries older than the retention period';

    public function handle(EntryWriter $entryWriter, AggregateStorage $aggregateStorage, IssueStorage $issueStorage): int
    {
        $hours = (int) ($this->option('hours') ?? config('monitor.retention.hours', 168));
        $before = CarbonImmutable::now()->subHours($hours);

        $deleted = $entryWriter->purge($before);

        // Re-sync open issues against the remaining entries, as the Issues page does.
        $this->syncOpenIssues($aggregateStorage, $issueStorage, $this->since(), $this->until());

        $expired = $issueStorage->expireStaleIssues();

        $this->info($deleted >= 0
            ? "Pruned {$deleted} entries older than {$hours} hours."
            : "Pruned entries older than {$hours} hours.");

        if ($expired > 0) {
            $this->info("Deleted {$expired} open issue(s) with no remaining data.");
        }

        return self::SUCCESS;
    }
}
