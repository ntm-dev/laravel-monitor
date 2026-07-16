---
name: livewire-conventions
description: Livewire/Alpine state-ownership conventions for this dashboard. Use when building or editing a Livewire component or a Blade view that mixes Livewire with Alpine.js in ntm-dev/laravel-monitor.
---

# Livewire Conventions

- Livewire owns state server-side; validate/authorize in component methods the same way you
  would in an HTTP request handler.
- Use Alpine.js (`x-data`) only for state that's purely client-side and doesn't need to hit
  the server — hover state, "copied to clipboard" flashes, which row is selected. See
  `resources/views/components/requests/timeline.blade.php` for the pattern: Livewire supplies
  the data, Alpine handles interaction on top of it.
- Don't reach for a full Livewire round-trip for something Alpine can do locally, and don't
  duplicate server data into Alpine state when it can just read from what Livewire already
  rendered.
