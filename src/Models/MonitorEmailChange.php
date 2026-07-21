<?php

namespace LaravelMonitor\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A pending "change my email" request. verified_at is null until the
 * requester proves they control the new inbox by clicking the emailed
 * link; rows with verified_at still null are never shown to an
 * approver, regardless of role.
 */
class MonitorEmailChange extends Model
{
    protected $fillable = ['user_id', 'new_email', 'token', 'verified_at', 'expires_at'];

    protected $casts = ['verified_at' => 'datetime', 'expires_at' => 'datetime'];

    public function getTable(): string
    {
        return config('monitor.auth.email_changes_table', 'monitor_email_changes');
    }

    public function getConnectionName(): ?string
    {
        return config('monitor.storage.database.connection');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(MonitorUser::class, 'user_id');
    }

    /**
     * @return array{emailChange: self, plainToken: string}
     */
    public static function createFor(MonitorUser $requester, string $newEmail): array
    {
        $plainToken = Str::random(40);

        $emailChange = static::query()->updateOrCreate(
            ['user_id' => $requester->id],
            [
                'new_email' => $newEmail,
                'token' => hash('sha256', $plainToken),
                'verified_at' => null,
                'expires_at' => Carbon::now()->addMinutes(60),
            ],
        );

        return ['emailChange' => $emailChange, 'plainToken' => $plainToken];
    }

    public static function findByPlainToken(string $plainToken): ?self
    {
        return static::query()->where('token', hash('sha256', $plainToken))->first();
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }
}
