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
        Schema::create($this->table(), function (Blueprint $table) {
            $table->id();
            $table->string('type', 32);
            $table->string('subtype', 32)->nullable();
            $table->string('key', 255)->nullable();
            $table->text('payload')->nullable();
            $table->unsignedInteger('duration')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamp('created_at');

            $table->index(['type', 'created_at']);
            $table->index(['type', 'subtype', 'created_at']);
            $table->index(['type', 'key']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    protected function table(): string
    {
        return config('monitor.storage.database.table', 'monitor_entries');
    }
};
