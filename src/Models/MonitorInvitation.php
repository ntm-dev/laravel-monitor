<?php

namespace LaravelMonitor\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A pending invite — deliberately its own table, not a "pending" row in
 * monitor_users, since a person who hasn't accepted yet isn't a real
 * account (no password, shouldn't be listed as a member, shouldn't be
 * assignable settings/team permissions).
 *
 * The token is hashed with SHA-256 (not Hash::make()/bcrypt) specifically
 * because it must be *queryable by value* from a single URL parameter —
 * bcrypt is salted and can't be looked up that way, which is exactly why
 * Laravel's own API-token-style packages use the same SHA-256 pattern
 * instead of the framework's usual password hashing.
 */
class MonitorInvitation extends Model
{
    protected $fillable = ['email', 'role', 'token', 'invited_by', 'expires_at'];

    protected $casts = ['expires_at' => 'datetime'];

    public function getTable(): string
    {
        return config('monitor.auth.invitations_table', 'monitor_invitations');
    }

    public function getConnectionName(): ?string
    {
        return config('monitor.storage.database.connection');
    }

    /**
     * @return array{invitation: self, plainToken: string}
     */
    public static function createFor(string $email, string $role, MonitorUser $inviter): array
    {
        $plainToken = Str::random(40);

        $invitation = static::query()->updateOrCreate(
            ['email' => $email],
            [
                'role' => $role,
                'token' => hash('sha256', $plainToken),
                'invited_by' => $inviter->id,
                'expires_at' => Carbon::now()->addHours(2),
            ],
        );

        return ['invitation' => $invitation, 'plainToken' => $plainToken];
    }

    public static function findByPlainToken(string $plainToken): ?self
    {
        return static::query()->where('token', hash('sha256', $plainToken))->first();
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}
