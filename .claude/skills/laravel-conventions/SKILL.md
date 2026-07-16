---
name: laravel-conventions
description: Laravel-framework conventions relevant to this package (routing, migrations). Use when adding links/routes or writing a database migration in ntm-dev/laravel-monitor.
---

# Laravel Conventions

This repo is a Laravel *package* (Testbench-driven, no `app/` directory), so most
application-level scaffolding guidance (artisan `make:model`, Eloquent API Resources, Vite)
doesn't apply here. What does apply:

- Prefer named routes and the `route()` helper over hardcoded URLs when linking between pages
  (see `resources/views/livewire/requests.blade.php` — `route('monitor.dashboard', [...])`).
- When a migration modifies an existing column, it must restate every attribute that column
  already had (type, nullable, default, precision, ...), or the ones left out get silently
  dropped. This package's `monitor_entries.duration` column has been touched by two
  consecutive migrations — check `database/migrations/` for the current shape before adding
  a third.
