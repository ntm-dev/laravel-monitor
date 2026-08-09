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
