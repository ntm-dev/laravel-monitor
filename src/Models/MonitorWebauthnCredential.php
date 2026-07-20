<?php

namespace LaravelMonitor\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MonitorWebauthnCredential extends Model
{
    public $timestamps = false;

    protected $fillable = ['user_id', 'credential_id', 'public_key', 'label', 'sign_count'];

    public function getTable(): string
    {
        return config('monitor.auth.webauthn_table', 'monitor_webauthn_credentials');
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
