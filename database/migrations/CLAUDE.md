# Migrations

**Single migration file, always.** This package is installed into other
Laravel apps purely to monitor them, and the integration only allows one
migration file. All tables live in
`database/migrations/2026_01_01_000000_create_monitor_table.php` — do not
add a second migration file for any reason, including a new table.

When the schema needs to change:
1. Edit the relevant `Schema::create()`/`Schema::table()` block in that one
   file directly, so a fresh install still produces the right structure.
2. Apply the same change to any already-migrated database (this repo's own
   dev setup, a consuming app) with a manual `ALTER`/`Schema::table()` call
   run directly (e.g. via `artisan tinker`) — never by writing a new
   migration file to carry the change.
