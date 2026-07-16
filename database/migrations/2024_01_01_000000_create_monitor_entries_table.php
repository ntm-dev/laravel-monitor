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
        Schema::create($this->entriesTable(), function (Blueprint $table) {
            $table->id();
            $table->string('type', 32);
            $table->string('subtype', 32)->nullable();
            $table->string('key', 255)->nullable();
            $table->text('payload')->nullable();
            // 3 decimal places (microsecond precision) so request/phase
            // timing measured via microtime() isn't floored away — see
            // Monitor::elapsedMsPrecise(). Entries whose duration comes from
            // a Laravel core event (e.g. QueryExecuted::$time) already arrive
            // rounded to 2 decimals upstream, so the extra digit is simply 0.
            $table->decimal('duration', 13, 3)->unsigned()->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('request_id', 36)->nullable();
            $table->decimal('start_offset', 13, 3)->unsigned()->nullable();
            $table->timestamp('created_at');

            // Two indexes sharing the [type, created_at] prefix, on purpose:
            //
            // - `[type, created_at]` (narrow) is what recent() and
            //   exceptionGroups() need for `ORDER BY created_at DESC, id DESC
            //   LIMIT n` — MySQL walks it backwards and stops at the limit
            //   ("Backward index scan" in EXPLAIN) with no sort step at all.
            //   A wider index with extra trailing columns loses that specific
            //   optimization and falls back to a full filesort of every
            //   matching row before the limit is applied — measured 2ms vs
            //   9.5s for the exact same query on a multi-million-row table,
            //   so this one has to stay narrow.
            // - `[type, created_at, duration, key, subtype]` is a *covering*
            //   index for the aggregate reads — stats(), routeStats(),
            //   aggregateByKey(), durationStats() — so they're satisfied
            //   entirely from the index instead of a row lookup back to the
            //   clustered index for every match. That row-lookup was the
            //   dominant cost of those queries at scale: once a WHERE type=/
            //   created_at>= filter matches a large fraction of the table,
            //   fetching `duration` one row at a time this way measured
            //   4-9x slower than the equivalent covering scan.
            //
            // Same split for the subtype-filtered variants below, except
            // countsPerBucket()/cacheKeyStats() (the only subtype-filtered
            // queries that need index order) don't carry a competing ORDER
            // BY, so one covering index serves them without the narrow/wide
            // split the unfiltered case needed.
            $table->index(['type', 'created_at']);
            $table->index(['type', 'created_at', 'duration', 'key', 'subtype']);
            $table->index(['type', 'subtype', 'created_at', 'duration', 'key']);
            $table->index(['type', 'key']);
            $table->index('user_id');
            $table->index(['type', 'request_id']);
        });

        // Pre-computed per-bucket counts, rolled up from monitor_entries by
        // the `monitor:aggregate` command — mirrors Laravel Pulse's entries/
        // aggregates split. Only carries totals (no key/user breakdown), so
        // it backs the unfiltered trend charts (Overview, Requests, Cache,
        // ...); a route/job/user-filtered chart still scans raw entries
        // directly. `subtype` is a plain empty string rather than nullable
        // so the unique index (and upsert's ON CONFLICT/ON DUPLICATE KEY
        // matching) behaves consistently across MySQL/Postgres/SQLite — a
        // nullable column there would let Postgres treat every NULL as
        // distinct and silently stop upserting.
        Schema::create($this->aggregatesTable(), function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('bucket');
            $table->unsignedMediumInteger('period');
            $table->string('type', 32);
            $table->string('subtype', 32)->default('');
            $table->string('aggregate', 16);
            $table->decimal('value', 20, 3);
            $table->unsignedInteger('count')->nullable();

            $table->unique(['bucket', 'period', 'type', 'subtype', 'aggregate'], 'monitor_aggregates_unique');
            $table->index(['period', 'bucket']);
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->aggregatesTable());
        Schema::dropIfExists($this->entriesTable());
    }

    protected function entriesTable(): string
    {
        return config('monitor.storage.database.table', 'monitor_entries');
    }

    protected function aggregatesTable(): string
    {
        return config('monitor.aggregates.table', 'monitor_aggregates');
    }
};
