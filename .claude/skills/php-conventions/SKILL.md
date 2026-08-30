---
name: php-conventions
description: PHP code style conventions for this repo (ntm-dev/laravel-monitor). Use whenever writing or editing PHP code here — control structures, constructors, type hints, comment style.
---

# PHP Conventions

- Follow the PSR coding standards as the baseline: **PSR-1** (`StudlyCaps` class names,
  `camelCase` method names, one class per file, no side effects at include-time) and **PSR-12**
  (4-space indentation, `<?php` on its own line with no closing `?>` tag, opening brace for a
  class/method on its own line, one statement per line, one `use` import per line). **PSR-4**
  governs autoloading — `LaravelMonitor\` maps to `src/` (see `composer.json`'s
  `autoload.psr-4`), so a class's namespace must mirror its path under `src/` exactly. Every
  other rule below refines PSR-12 for this codebase's own taste (callable priority, `use
  function` imports, comment style, ...); none of them override it.
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
- Callable priority, highest first:
  1. **First-class callable syntax** (`foo(...)`, `$this->method(...)`, `self::method(...)`,
     `SomeClass::method(...)`) when a closure would do nothing but forward its arguments to an
     existing function/method unchanged — e.g. `->map($this->hydrate(...))`, not
     `->map(fn ($row) => $this->hydrate($row))`. Shorter, and PHP resolves/type-checks the
     target at the call site instead of inside an opaque closure body.
  2. Otherwise, an **arrow function** (`fn () => ...`) for anything that still fits one
     expression — extra args, a transformation, a property access — over
     `function () { return ...; }`. They read shorter and capture the outer scope automatically.
  3. A full closure only when the body genuinely needs multiple statements or a by-reference
     `use`.
- Mark an arrow function or closure `static` when its body never touches `$this`:
  `static fn ($row) => $row->key`. Stops PHP binding the enclosing object into the closure, so it
  can't accidentally keep that object alive or expose its internals. (First-class callable syntax
  has no `static` form of its own — `self::method(...)`/a plain function reference is already
  static-safe with nothing to mark; `$this->method(...)` binds `$this` because the call genuinely
  needs it.)
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
