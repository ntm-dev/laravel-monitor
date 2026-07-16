---
name: foundation-conventions
description: Baseline working conventions for ntm-dev/laravel-monitor. Use at the start of any non-trivial code change in this repo, before writing new files or components.
---

# Foundation Conventions

- Check sibling files for existing structure/approach before writing something new — most UI
  needs are already covered by an `x-monitor::*` component (`resources/views/components/`);
  most recording patterns are already covered by an existing `src/Recorders/*` class.
- Don't create documentation files unless explicitly requested.
- Don't write one-off verification scripts or reach for `artisan tinker` when the test suite
  already covers the behavior — a passing/failing test is more durable evidence than a
  throwaway script.
- Only commit when explicitly asked — drafting a commit message is not permission to commit.
