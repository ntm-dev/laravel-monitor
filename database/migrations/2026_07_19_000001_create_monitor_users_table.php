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
        Schema::create($this->usersTable(), function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('role', 16)->default('viewer');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->usersTable());
    }

    protected function usersTable(): string
    {
        return config('monitor.auth.table', 'monitor_users');
    }
};
