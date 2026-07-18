<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getConnection()
    {
        return config('monitor.storage.database.connection');
    }

    public function up(): void
    {
        // One row per issue (an exception fingerprint or a performance-
        // threshold breach), keyed by [type, key] — mirrors monitor_entries'
        // own type/key vocabulary rather than introducing a separate one.
        // Kept apart from monitor_entries: this table holds durable
        // Open/Resolved/Ignored state a user sets, not immutable recorded
        // facts, and is never pruned by monitor:prune.
        Schema::create($this->issuesTable(), function (Blueprint $table) {
            $table->id();
            $table->string('type', 32);
            $table->string('key', 255);
            $table->string('status', 16)->default('open');
            $table->timestamp('first_seen');
            $table->timestamp('last_seen');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->unique(['type', 'key']);
            $table->index(['type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->issuesTable());
    }

    protected function issuesTable(): string
    {
        return config('monitor.issues.table', 'monitor_issues');
    }
};
