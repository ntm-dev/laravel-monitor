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
        Schema::table($this->table(), function (Blueprint $table) {
            $table->string('request_id', 36)->nullable()->after('user_id');
            $table->unsignedInteger('start_offset')->nullable()->after('request_id');

            $table->index(['type', 'request_id']);
        });
    }

    public function down(): void
    {
        Schema::table($this->table(), function (Blueprint $table) {
            $table->dropIndex([$this->table().'_type_request_id_index']);
            $table->dropColumn(['request_id', 'start_offset']);
        });
    }

    protected function table(): string
    {
        return config('monitor.storage.database.table', 'monitor_entries');
    }
};
