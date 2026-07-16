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
