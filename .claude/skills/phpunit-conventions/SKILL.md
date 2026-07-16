---
name: phpunit-conventions
description: How to run and maintain PHPUnit tests in this repo (ntm-dev/laravel-monitor). Use whenever writing, editing, or running tests, or after changing code that has test coverage.
---

# PHPUnit Conventions

This package uses PHPUnit (not Pest) — `tests/` contains plain PHPUnit test classes bootstrapped
via Orchestra Testbench (`tests/TestCase.php`).

- After changing a test, run just that one first:
  `./vendor/bin/phpunit --filter=testName` or `./vendor/bin/phpunit tests/Path/To/Test.php`.
- Don't remove tests or test files without approval — they're not scratch/helper files.
- Once the related test(s) pass, run the full suite before calling anything done:
  `composer test` (= `phpunit`). It's fast (~18 tests currently), so there's little reason
  to skip it.
- Tests should cover the happy path, failure paths, and edge cases — not just the happy path.
- `tests/TestCase.php::setUp()` explicitly flushes the Monitor buffer and purges storage
  before each test, because the Queries recorder captures RefreshDatabase's own migration
  queries otherwise. Don't remove that without understanding why it's there.
