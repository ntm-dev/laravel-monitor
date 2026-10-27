<?php

namespace LaravelMonitor\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * A dashboard user, separate from the host app's User model. One team per
 * installation, so `role` (owner/admin/viewer) is all the membership data.
 */
class MonitorUser extends Authenticatable
{
    protected $fillable = ['name', 'email', 'username', 'password', 'role', 'totp_secret', 'totp_enabled_at', 'totp_recovery_codes'];

    protected $hidden = ['password', 'totp_secret', 'totp_recovery_codes'];

    protected $casts = [
        'totp_enabled_at' => 'datetime',
        'totp_secret' => 'encrypted',
        'totp_recovery_codes' => 'array',
    ];

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

    public function hasTotpEnabled(): bool
    {
        return $this->totp_enabled_at !== null;
    }

    public static function guardName(): string
    {
        return config('monitor.auth.guard', 'monitor');
    }
}
