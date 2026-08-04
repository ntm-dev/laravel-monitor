<?php

namespace LaravelMonitor\Http\Controllers\Concerns;

use function preg_quote;
use function preg_replace;

/**
 * @internal
 */
trait NormalizesQueue
{
    /**
     * @var array<string, string>
     */
    private array $normalizedQueues = [];

    private function normalizeQueue(string $connection, string $queue): string
    {
        $key = "{$connection}:{$queue}";

        if (isset($this->normalizedQueues[$key])) {
            return $this->normalizedQueues[$key];
        }

        $config = config("queue.connections.{$connection}", []);

        if (($config['driver'] ?? '') !== 'sqs') {
            return $this->normalizedQueues[$key] = $queue;
        }

        if ($config['prefix'] ?? false) {
            $prefix = preg_quote($config['prefix'], '#');

            $queue = preg_replace("#^{$prefix}/#", '', $queue) ?? $queue;
        }

        if ($config['suffix'] ?? false) {
            $suffix = preg_quote($config['suffix'], '#');

            $queue = preg_replace("#{$suffix}$#", '', $queue) ?? $queue;
        }

        return $this->normalizedQueues[$key] = $queue;
    }
}
