<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function getConnection()
    {
        return config('monitor.storage.database.connection');
    }

    /**
     * Widen `duration` from whole milliseconds to 2 decimal places, so a
     * request/query/job duration like 200.23ms is stored precisely instead
     * of rounding away the fraction. Uses raw DDL rather than Blueprint's
     * column() ->change(), which needs doctrine/dbal — a dependency this
     * package doesn't otherwise require. SQLite stores fractional values in
     * an INTEGER-affinity column fine (type affinity, not enforcement), so
     * there's nothing to alter there.
     */
    public function up(): void
    {
        $connection = DB::connection($this->getConnection());
        $table = $this->table();

        match ($connection->getDriverName()) {
            'mysql' => $connection->statement("ALTER TABLE `{$table}` MODIFY `duration` DECIMAL(10,2) UNSIGNED NULL"),
            'pgsql' => $connection->statement("ALTER TABLE \"{$table}\" ALTER COLUMN \"duration\" TYPE DECIMAL(10,2) USING \"duration\"::decimal(10,2)"),
            default => null,
        };
    }

    public function down(): void
    {
        $connection = DB::connection($this->getConnection());
        $table = $this->table();

        match ($connection->getDriverName()) {
            'mysql' => $connection->statement("ALTER TABLE `{$table}` MODIFY `duration` INT UNSIGNED NULL"),
            'pgsql' => $connection->statement("ALTER TABLE \"{$table}\" ALTER COLUMN \"duration\" TYPE INTEGER USING ROUND(\"duration\")::integer"),
            default => null,
        };
    }

    protected function table(): string
    {
        return config('monitor.storage.database.table', 'monitor_entries');
    }
};
