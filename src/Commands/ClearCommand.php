<?php

namespace LaravelMonitor\Commands;

use Illuminate\Console\Command;
use LaravelMonitor\Contracts\Storage;

class ClearCommand extends Command
{
    protected $signature = 'monitor:clear';

    protected $description = 'Delete all monitor entries';

    public function handle(Storage $storage): int
    {
        $storage->purge();

        $this->info('All monitor entries deleted.');

        return self::SUCCESS;
    }
}
