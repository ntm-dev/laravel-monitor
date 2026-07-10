<?php

namespace LaravelMonitor\Recorders;

use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Contracts\Events\Dispatcher;

class Authentication extends Recorder
{
    public function register(Dispatcher $events): void
    {
        $events->listen(Login::class, [$this, 'recordLogin']);
        $events->listen(Logout::class, [$this, 'recordLogout']);
        $events->listen(Failed::class, [$this, 'recordFailed']);
    }

    public function recordLogin(Login $event): void
    {
        $this->monitor->record(
            type: 'auth',
            key: $this->identifier($event->user),
            payload: ['guard' => $event->guard],
            subtype: 'login',
            userId: $event->user?->getAuthIdentifier(),
        );
    }

    public function recordLogout(Logout $event): void
    {
        $this->monitor->record(
            type: 'auth',
            key: $this->identifier($event->user),
            payload: ['guard' => $event->guard],
            subtype: 'logout',
            userId: $event->user?->getAuthIdentifier(),
        );
    }

    public function recordFailed(Failed $event): void
    {
        $identifier = $event->user
            ? $this->identifier($event->user)
            : (string) ($event->credentials['email'] ?? $event->credentials['username'] ?? 'unknown');

        $this->monitor->record(
            type: 'auth',
            key: $identifier,
            payload: ['guard' => $event->guard],
            subtype: 'failed',
            userId: $event->user?->getAuthIdentifier(),
        );
    }

    protected function identifier($user): string
    {
        if ($user === null) {
            return 'unknown';
        }

        return (string) ($user->email ?? $user->name ?? get_class($user).'#'.$user->getAuthIdentifier());
    }
}
