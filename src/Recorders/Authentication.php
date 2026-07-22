<?php

namespace LaravelMonitor\Recorders;

use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Contracts\Events\Dispatcher;
use LaravelMonitor\Models\MonitorUser;

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
        if ($this->isMonitorsOwnGuard($event->guard)) {
            return;
        }

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
        if ($this->isMonitorsOwnGuard($event->guard)) {
            return;
        }

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
        if ($this->isMonitorsOwnGuard($event->guard)) {
            return;
        }

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

    /**
     * The Monitor dashboard has its own, separate auth system (MonitorUser,
     * guard configurable via monitor.auth.guard) — its own logins/logouts
     * aren't activity of the application being monitored and must not be
     * recorded as such, mirroring how Recorders\Requests already excludes
     * requests to the dashboard's own routes.
     */
    protected function isMonitorsOwnGuard(string $guard): bool
    {
        return $guard === MonitorUser::guardName();
    }

    protected function identifier($user): string
    {
        if ($user === null) {
            return 'unknown';
        }

        return (string) ($user->email ?? $user->name ?? get_class($user).'#'.$user->getAuthIdentifier());
    }
}
