<?php

namespace LaravelMonitor\Commands;

use Illuminate\Console\Command;
use LaravelMonitor\Models\MonitorUser;
use LaravelMonitor\Support\SetupCode;

class SetupCodeCommand extends Command
{
    protected $signature = 'monitor:setup-code';

    protected $description = 'Generate a one-time code to verify server access before creating the dashboard owner account';

    public function handle(): int
    {
        if (MonitorUser::query()->exists()) {
            $this->error('Setup is already complete — a dashboard account already exists.');

            return self::FAILURE;
        }

        $code = SetupCode::generate();

        $this->info("Setup code: {$code}");
        $this->line('Paste this into the setup page in your browser. It expires in 10 minutes.');

        return self::SUCCESS;
    }
}
