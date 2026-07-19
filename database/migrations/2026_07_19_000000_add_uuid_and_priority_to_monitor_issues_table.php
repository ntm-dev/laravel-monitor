<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Ramsey\Uuid\Uuid;

return new class extends Migration
{
    public function getConnection()
    {
        return config('monitor.storage.database.connection');
    }

    public function up(): void
    {
        // `uuid` isn't enforced NOT NULL at the schema level — that would
        // need Blueprint::change(), which requires doctrine/dbal, not a
        // guaranteed dependency on Laravel 10. Every insert path
        // (Jobs/Exceptions recorders go through DatabaseStorage::syncIssues()
        // and setIssueStatus()/setIssuePriority()) always supplies one, and
        // this migration backfills existing rows below, so it's never
        // actually null in practice.
        Schema::table($this->issuesTable(), function (Blueprint $table) {
            $table->uuid('uuid')->nullable()->unique()->after('id');
            $table->string('priority', 16)->default('none')->after('status');
        });

        DB::connection($this->getConnection())
            ->table($this->issuesTable())
            ->whereNull('uuid')
            ->orderBy('id')
            ->get(['id'])
            ->each(fn ($row) => DB::connection($this->getConnection())
                ->table($this->issuesTable())
                ->where('id', $row->id)
                ->update(['uuid' => Uuid::uuid7()->toString()]));
    }

    public function down(): void
    {
        Schema::table($this->issuesTable(), function (Blueprint $table) {
            $table->dropUnique([$this->issuesTable().'_uuid_unique']);
            $table->dropColumn(['uuid', 'priority']);
        });
    }

    protected function issuesTable(): string
    {
        return config('monitor.issues.table', 'monitor_issues');
    }
};
