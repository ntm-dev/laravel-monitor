<?php

namespace LaravelMonitor\Facades;

use Illuminate\Support\Facades\Facade;
use LaravelMonitor\Support\RecordType;

/**
 * @method static void record(RecordType $type, ?string $key = null, array $payload = [], ?float $duration = null, ?string $subtype = null, int|string|null $userId = null)
 * @method static bool enabled()
 * @method static void flush()
 * @method static mixed ignore(callable $callback)
 * @method static void stopRecording()
 * @method static void startRecording()
 * @method static \LaravelMonitor\Contracts\EntryWriter storage()
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
