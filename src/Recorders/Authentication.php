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

        // SessionGuard::logout() fires Logout before clearing the user, so
        // earlier entries in this lifecycle can still resolve to them.
        $this->monitor->rememberLoggedOutUser($event->guard, $event->user);

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

    /** The dashboard's own auth guard (MonitorUser) isn't app activity. */
    protected function isMonitorsOwnGuard(string $guard): bool
    {
        return $guard === MonitorUser::guardName();
    }

    /** Guard-prefixed: two guards can reuse the same id (and model class) for different users. */
    protected function identifier(string $guard, ?Authenticatable $user): string
    {
        if ($user === null) {
            return "{$guard}:unknown";
        }

        return "{$guard}:".get_class($user).'#'.$user->getAuthIdentifier();
    }

    /** Guard-prefixed like identifier(), for the user_id column. */
    protected function userId(string $guard, ?Authenticatable $user): ?string
    {
        return $user === null ? null : "{$guard}:{$user->getAuthIdentifier()}";
    }
}
