<?php

namespace LaravelMonitor\Recorders;

use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Events\Dispatcher;
use LaravelMonitor\Models\MonitorUser;
use LaravelMonitor\Support\RecordType;

use function get_class;

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
            type: RecordType::Auth,
            key: $this->identifier($event->guard, $event->user),
            payload: ['guard' => $event->guard],
            subtype: 'login',
            userId: $this->userId($event->guard, $event->user),
        );
    }

    public function recordLogout(Logout $event): void
    {
        if ($this->isMonitorsOwnGuard($event->guard)) {
            return;
        }

        $this->monitor->record(
            type: RecordType::Auth,
            key: $this->identifier($event->guard, $event->user),
            payload: ['guard' => $event->guard],
            subtype: 'logout',
            userId: $this->userId($event->guard, $event->user),
        );
    }

    public function recordFailed(Failed $event): void
    {
        if ($this->isMonitorsOwnGuard($event->guard)) {
            return;
        }

        $identifier = $event->user
            ? $this->identifier($event->guard, $event->user)
            : "{$event->guard}:".(string) ($event->credentials['email'] ?? $event->credentials['username'] ?? 'unknown');

        $this->monitor->record(
            type: RecordType::Auth,
            key: $identifier,
            payload: ['guard' => $event->guard],
            subtype: 'failed',
            userId: $this->userId($event->guard, $event->user),
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

    /**
     * Prefixed with the guard name — two different guards can independently
     * assign the same id to two entirely different users (separate
     * providers/tables), and even share a user model class. Without the
     * guard qualifier those would record under an identical key, showing up
     * on the dashboard as if a single user logged in/out across both.
     */
    protected function identifier(string $guard, ?Authenticatable $user): string
    {
        if ($user === null) {
            return "{$guard}:unknown";
        }

        return "{$guard}:".get_class($user).'#'.$user->getAuthIdentifier();
    }

    /**
     * Same guard-qualification as identifier() (see its docblock), applied
     * to the entry's user_id column instead of its key — every "by user"
     * query/filter across the dashboard reads that column, so it needs the
     * same disambiguation.
     */
    protected function userId(string $guard, ?Authenticatable $user): ?string
    {
        return $user === null ? null : "{$guard}:{$user->getAuthIdentifier()}";
    }
}
