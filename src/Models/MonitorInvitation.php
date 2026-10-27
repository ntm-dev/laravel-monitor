<?php

namespace LaravelMonitor\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A pending invite, kept apart from monitor_users until accepted. The token
 * is SHA-256 hashed (not bcrypt) so it can be looked up by value.
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

    public function invitedBy(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(MonitorUser::class, 'invited_by');
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
