---
name: php-conventions
description: PHP code style conventions for this repo (ntm-dev/laravel-monitor). Use whenever writing or editing PHP code here — control structures, constructors, type hints, comment style.
---

# PHP Conventions

- Always use curly braces for control structures, even one-liner bodies.
- Use PHP 8 constructor property promotion when a class has a constructor:
  `public function __construct(public Storage $storage) {}`.
- Explicit return type and parameter type hints on every method — this codebase already does
  this consistently (see `src/Recorders/*`), keep it that way.
- Prefer PHPDoc blocks over inline comments. Only add an inline comment for something
  genuinely non-obvious — a hidden constraint, a workaround for a specific bug, behavior that
  would surprise a reader. Don't restate what the code already says.
- Prefer interpolation over `.` concatenation when embedding a value in a string:
  `"monitor::messages.{$key}"`, not
  `'monitor::messages.'.$key`. Use braces around the expression
  (`"{$task->key}"`), not the bare `"$var"` form. Concatenation is still the right call for
  joining two whole expressions (`$prefix.$suffix`) or building a string across lines.
- Prefer arrow functions (`fn () => ...`) over `function () { return ...; }` for single-expression
  closures — they read shorter and capture the outer scope automatically. Use a full closure only
  when the body genuinely needs multiple statements or a by-reference `use`.
- Mark a closure `static` when its body never touches `$this`: `static fn ($row) => $row->key`.
  Stops PHP binding the enclosing object into the closure, so it can't accidentally keep that
  object alive or expose its internals.
- Import global (non-framework) PHP functions used inside a namespaced class with
  `use function ...;` at the top of the file — e.g. `use function is_object;`,
  `use function str_starts_with;` — instead of calling them unqualified. Avoids the IDE hint
  ("Special function '...' should be called in global namespace to allow compiler
  optimization", PHP6616) and lets the compiler resolve the call without a namespace fallback
  lookup at runtime. Applies to functions like `is_object`, `get_class`, `str_starts_with`,
  `substr`, `strlen`, `implode`, `ltrim`, `preg_match`, etc. — not to framework helpers
  (`base_path()`, `config()`, ...) or Laravel facades/classes, which already resolve normally.
- Prefer the array spread operator over `array_merge()` when merging array literals/variables:
  `[...$a, ...$b, 'extra']`, not `array_merge($a, $b, ['extra'])`. Avoids the IDE hint ("replace
  with array spread operator", PHP7103) and is directly re-indexed inline. `array_merge()` still
  the right call when a variable holding the merge arguments is dynamic/computed (e.g. an array
  of arrays via `...$dynamic` isn't valid), or when string keys must be preserved with later
  values overwriting earlier ones the way `array_merge()` does (spread with string keys throws
  on duplicates in PHP < 8.1 and this repo doesn't rely on that overwrite semantics elsewhere).
- Avoid hard-coded string/int literals for a fixed, known set of values (a status/grouping code,
  a mode flag, a lookup key repeated across files) — prefer a backed `enum` (or a class `const`
  for a single value) as the one source of truth, referenced via `::CaseName->value` everywhere
  the literal would otherwise appear. See `LaravelMonitor\Support\HttpStatusGroup` (backs the
  `monitor_entries.subtype` values '2xx'/'3xx'/'4xx'/'5xx'/'net_err', shared by
  `Recorders\Requests`, `Recorders\OutgoingRequests`, and every read-side query that groups by
  subtype) and `LaravelMonitor\Support\RecordType` for the existing pattern. Catches typos at
  the type level and keeps every call site in sync when a value changes — a literal `'net_err'`
  repeated across a recorder and several Livewire/Storage call sites is exactly the kind of
  duplication this avoids.
