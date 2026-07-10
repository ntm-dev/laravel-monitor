<?php

namespace LaravelMonitor\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static void record(string $type, ?string $key = null, array $payload = [], ?int $duration = null, ?string $subtype = null, int|string|null $userId = null)
 * @method static bool enabled()
 * @method static void flush()
 * @method static mixed ignore(callable $callback)
 * @method static void stopRecording()
 * @method static void startRecording()
 * @method static \LaravelMonitor\Contracts\Storage storage()
 *
 * @see \LaravelMonitor\Monitor
 */
class Monitor extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \LaravelMonitor\Monitor::class;
    }
}
