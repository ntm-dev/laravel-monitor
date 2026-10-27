<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The package's only migration file; never add a second one. Change the schema
 * here, then apply the same change to migrated databases with a manual ALTER.
 */
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
            $table->longText('payload')->nullable();
            // Milliseconds; timings are microsecond-precise (3 decimals), 6 is headroom.
            $table->decimal('duration', 16, 6)->unsigned()->nullable();
            // String: holds "{guard}:{id}", and ids may be non-numeric (UUID/ULID).
            $table->string('user_id', 255)->nullable();
            $table->string('request_id', 36)->nullable();
            $table->decimal('start_offset', 16, 6)->unsigned()->nullable();
            $table->timestamp('created_at', 6);

            // Narrow [type, created_at] serves recent()'s ORDER BY ... LIMIT as a
            // backward scan; the wide ones cover stats()/routeStats() without row
            // lookups. [request_id, type] leads on request_id: timelineFor() filters
            // type only with `!=`.
            $table->index(['type', 'created_at']);
            $table->index(['type', 'created_at', 'duration', 'key', 'subtype']);
            $table->index(['type', 'subtype', 'created_at', 'duration', 'key']);
            $table->index(['type', 'key']);
            $table->index('user_id');
            $table->index(['request_id', 'type']);
        });

        // Per-bucket totals rolled up by `monitor:aggregate`, backing unfiltered
        // trend charts. `subtype` defaults to '' (not NULL) so the unique index
        // matches on upsert; Postgres treats NULLs as distinct.
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

        Schema::create($this->usersTable(), function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('username')->nullable()->unique();
            $table->string('password');
            $table->string('role', 16)->default('viewer');
            $table->text('totp_secret')->nullable();
            $table->timestamp('totp_enabled_at')->nullable();
            $table->json('totp_recovery_codes')->nullable();
            $table->timestamps();
        });

        // SHA-256 token (not bcrypt) so it can be looked up by value.
        Schema::create($this->invitationsTable(), function (Blueprint $table) {
            $table->id();
            $table->string('email');
            $table->string('role', 16);
            $table->string('token', 64)->unique();
            $table->unsignedBigInteger('invited_by');
            $table->timestamp('expires_at');
            $table->timestamps();
        });

        Schema::create($this->passwordResetsTable(), function (Blueprint $table) {
            $table->id();
            $table->string('email');
            $table->string('token', 64)->unique();
            $table->timestamps();
        });

        Schema::create($this->emailChangesTable(), function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('new_email');
            $table->string('token', 64)->unique();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();
        });

        Schema::create($this->webauthnCredentialsTable(), function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('credential_id')->unique();
            $table->text('public_key');
            $table->string('label');
            $table->unsignedInteger('sign_count')->default(0);
            $table->timestamp('created_at');

            $table->index('user_id');
        });

        Schema::create($this->oauthAccountsTable(), function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('provider', 16);
            $table->string('provider_user_id');
            $table->timestamps();

            $table->unique(['provider', 'provider_user_id']);
            $table->index('user_id');
        });

        // One row per issue, keyed by monitor_entries' [type, key], holding
        // user-set status; monitor:prune only deletes open issues left without entries.
        Schema::create($this->issuesTable(), function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->nullable()->unique();
            $table->string('type', 32);
            $table->string('key', 255);
            $table->string('status', 16)->default('open');
            $table->string('priority', 16)->default('none');
            $table->timestamp('first_seen', 6);
            $table->timestamp('last_seen', 6);
            $table->timestamp('resolved_at', 6)->nullable();
            $table->timestamps();

            $table->unique(['type', 'key']);
            $table->index(['type', 'status']);
        });

        // Backs the 'database' aggregate-cache driver; same shape as Laravel's cache table.
        Schema::create($this->cacheTable(), function (Blueprint $table) {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->integer('expiration');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->cacheTable());
        Schema::dropIfExists($this->issuesTable());
        Schema::dropIfExists($this->oauthAccountsTable());
        Schema::dropIfExists($this->webauthnCredentialsTable());
        Schema::dropIfExists($this->emailChangesTable());
        Schema::dropIfExists($this->passwordResetsTable());
        Schema::dropIfExists($this->invitationsTable());
        Schema::dropIfExists($this->usersTable());
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

    protected function usersTable(): string
    {
        return config('monitor.auth.table', 'monitor_users');
    }

    protected function invitationsTable(): string
    {
        return config('monitor.auth.invitations_table', 'monitor_invitations');
    }

    protected function passwordResetsTable(): string
    {
        return config('monitor.auth.password_resets_table', 'monitor_password_resets');
    }

    protected function emailChangesTable(): string
    {
        return config('monitor.auth.email_changes_table', 'monitor_email_changes');
    }

    protected function webauthnCredentialsTable(): string
    {
        return config('monitor.auth.webauthn_table', 'monitor_webauthn_credentials');
    }

    protected function oauthAccountsTable(): string
    {
        return config('monitor.auth.oauth_accounts_table', 'monitor_oauth_accounts');
    }

    protected function issuesTable(): string
    {
        return config('monitor.issues.table', 'monitor_issues');
    }

    protected function cacheTable(): string
    {
        return config('monitor.aggregate_cache.table', 'monitor_cache');
    }
};
