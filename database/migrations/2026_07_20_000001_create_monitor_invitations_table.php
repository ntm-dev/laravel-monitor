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
        Schema::create($this->invitationsTable(), function (Blueprint $table) {
            $table->id();
            $table->string('email');
            $table->string('role', 16);
            $table->string('token', 64)->unique();
            $table->unsignedBigInteger('invited_by');
            $table->timestamp('expires_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->invitationsTable());
    }

    protected function invitationsTable(): string
    {
        return config('monitor.auth.invitations_table', 'monitor_invitations');
    }
};
