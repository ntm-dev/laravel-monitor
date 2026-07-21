<?php

namespace LaravelMonitor\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A one-time password-reset token. Hashed with SHA-256 (not
 * Hash::make()/bcrypt) for the same reason as MonitorInvitation::token —
 * it must be queryable by value from a single URL parameter.
 */
class MonitorPasswordReset extends Model
{
    protected $fillable = ['email', 'token'];

    public function getTable(): string
    {
        return config('monitor.auth.password_resets_table', 'monitor_password_resets');
    }

    public function getConnectionName(): ?string
    {
        return config('monitor.storage.database.connection');
    }

    /**
     * @return array{reset: self, plainToken: string}
     */
    public static function createFor(string $email): array
    {
        $plainToken = Str::random(40);

        $reset = static::query()->updateOrCreate(
            ['email' => $email],
            ['token' => hash('sha256', $plainToken)],
        );

        return ['reset' => $reset, 'plainToken' => $plainToken];
    }

    public static function findByPlainToken(string $plainToken): ?self
    {
        return static::query()->where('token', hash('sha256', $plainToken))->first();
    }

    public function isExpired(): bool
    {
        return $this->created_at->addMinutes(60)->isPast();
    }
}
