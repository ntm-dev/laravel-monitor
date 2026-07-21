<?php

namespace LaravelMonitor\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MonitorOauthAccount extends Model
{
    protected $fillable = ['user_id', 'provider', 'provider_user_id'];

    public function getTable(): string
    {
        return config('monitor.auth.oauth_accounts_table', 'monitor_oauth_accounts');
    }

    public function getConnectionName(): ?string
    {
        return config('monitor.storage.database.connection');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(MonitorUser::class, 'user_id');
    }
}
