<?php

namespace LaravelMonitor;

use Illuminate\Support\Manager;
use LaravelMonitor\Storage\DatabaseStorage;

/**
 * Resolves the configured storage driver. Add custom drivers from a service
 * provider:
 *
 *     app(StorageManager::class)->extend('redis', fn ($app) => new RedisStorage(...));
 */
class StorageManager extends Manager
{
    public function getDefaultDriver(): string
    {
        return $this->config->get('monitor.storage.driver', 'database');
    }

    protected function createDatabaseDriver(): DatabaseStorage
    {
        return new DatabaseStorage(
            $this->container->make('db'),
            $this->config->get('monitor.storage.database', []),
        );
    }
}
