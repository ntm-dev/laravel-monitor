<?php

namespace LaravelMonitor\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * The package's own dashboard user — completely separate from the host
 * app's own User model. One flat table (no `teams` table): this package
 * supports exactly one team per installation, so `role` alone is enough
 * to express owner/admin/viewer.
 */
class MonitorUser extends Authenticatable
{
    protected $fillable = ['name', 'email', 'password', 'role'];

    protected $hidden = ['password'];

    public function getTable(): string
    {
        return config('monitor.auth.table', 'monitor_users');
    }

    public function getConnectionName(): ?string
    {
        return config('monitor.storage.database.connection');
    }

    public function canManageSettings(): bool
    {
        return in_array($this->role, ['owner', 'admin'], true);
    }

    public function isOwner(): bool
    {
        return $this->role === 'owner';
    }

    public function canManageTeam(): bool
    {
        return in_array($this->role, ['owner', 'admin'], true);
    }

    public static function guardName(): string
    {
        return config('monitor.auth.guard', 'monitor');
    }
}
