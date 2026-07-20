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
            $table->unsignedBigInteger('user_id');
            $table->string('new_email');
            $table->string('token', 64)->unique();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    protected function table(): string
    {
        return config('monitor.auth.email_changes_table', 'monitor_email_changes');
    }
};
