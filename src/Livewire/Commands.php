<?php

namespace LaravelMonitor\Livewire;

class Commands extends Card
{
    protected function view(): string
    {
        return 'monitor::livewire.commands';
    }

    protected function data(): array
    {
        $since = $this->since();
        $until = $this->until();
        $storage = $this->storage();

        $success = $storage->aggregateByKey('command', $since, 'success', 50, 'count', $until);
        $failed = $storage->aggregateByKey('command', $since, 'failed', 50, 'count', $until);

        $commands = collect();

        foreach ([$success, $failed] as $index => $groups) {
            $column = ['success', 'failed'][$index];

            foreach ($groups as $group) {
                $command = $commands->get($group->key) ?? (object) [
                    'key' => $group->key,
                    'success' => 0,
                    'failed' => 0,
                    'avg_duration' => null,
                ];

                $command->{$column} = $group->count;

                if ($column === 'success') {
                    $command->avg_duration = $group->avg_duration;
                }

                $commands->put($group->key, $command);
            }
        }

        return [
            'commands' => $commands->sortByDesc(fn ($command) => $command->success + $command->failed)->take($this->limit)->values(),
            'threshold' => (int) config('monitor.thresholds.command', 1000),
        ];
    }
}
