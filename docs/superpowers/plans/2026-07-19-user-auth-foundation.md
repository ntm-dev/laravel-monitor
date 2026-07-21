# User/Team Foundation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give `laravel-monitor` its own login system — a `monitor_users` table, a dedicated `monitor` auth guard, a first-run "create the owner" flow, and role enforcement (owner/admin/viewer) proven on the existing Settings routes.

**Architecture:** A single new Eloquent model (`MonitorUser`) backed by one new table, authenticated through a package-registered Laravel guard that's completely independent of the host app's own `Auth` guards (Laravel isolates session state per guard name automatically). The existing `viewMonitor` Gate keeps its exact current role as a global on/off switch, checked first, unchanged. A new `EnsureMonitorAuthenticated` middleware sits inside that Gate check and requires a `monitor`-guard session for every route except `/setup` and `/login`. Plain controllers (no Livewire) for setup/login, matching the existing `SettingsController`/`RequestDetailController` pattern already used for non-reactive, form-post pages in this codebase.

**Tech Stack:** Laravel 10–13 (this package's supported range), Laravel's built-in `Auth`/`Hash` facades and `Illuminate\Foundation\Auth\User` base class (no new Composer dependencies), Blade (no build step), PHPUnit 10+.

## Global Constraints

- Support Laravel 10 through 13. Do **not** use the Eloquent `'hashed'` cast
  type (`protected $casts = ['password' => 'hashed']`) — verified absent
  from `Illuminate\Database\Eloquent\Casts` at the `v10.0.0` tag (added in
  a later Laravel 10.x/11.x point release, not guaranteed on this
  package's floor). Hash passwords explicitly with `Hash::make()` in the
  controllers instead — the traditional, always-portable approach.
- Migration `getConnection()` pattern: return
  `config('monitor.storage.database.connection')`, matching
  `database/migrations/2024_01_01_000000_create_monitor_entries_table.php`.
- PHP conventions for this repo: curly braces on every control structure
  (even one-liners), PHP 8 constructor property promotion, explicit
  return/parameter type hints on every method, PHPDoc over inline
  comments (inline comments only for genuinely non-obvious behavior).
- **This machine's Herd PHP CLI is broken** (missing dylib) — run every
  PHP/PHPUnit command with `/opt/homebrew/bin/php` explicitly, e.g.
  `/opt/homebrew/bin/php vendor/bin/phpunit ...`, never bare `php`.
- Baseline on this branch (`feat/team-auth-management`, based on
  `master`): **45 tests, 161 assertions, all green.** Every task must
  leave the suite green, with one deliberate exception noted in Task 6
  (wiring auth into the live routes and adding the test fixture that
  keeps old tests passing are inseparable — see that task for why they're
  one task, not two).
- Do not commit unless a task step explicitly says to (this plan's steps
  do say so, per task, following the established session convention).

---

### Task 1: `monitor_users` migration + config

**Files:**
- Create: `database/migrations/2026_07_19_000001_create_monitor_users_table.php`
- Modify: `config/monitor.php`
- Test: `tests/MonitorTest.php` (add to existing file)

**Interfaces:**
- Produces: table `monitor_users` (configurable name via
  `config('monitor.auth.table', 'monitor_users')`) with columns `id`,
  `name`, `email` (unique), `password`, `role` (string(16), default
  `'viewer'`), timestamps. New config keys `monitor.auth.guard` (default
  `'monitor'`) and `monitor.auth.table` (default `'monitor_users'`).

- [ ] **Step 1: Write the failing test**

Add to `tests/MonitorTest.php` (the file already has
`use Illuminate\Support\Facades\Gate;` and similar imports — no new
imports needed for this test since it only uses fully-qualified
`\Illuminate\Support\Facades\Schema` and `\Illuminate\Support\Facades\DB`):

```php
public function test_monitor_users_table_exists_with_expected_columns(): void
{
    $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumns('monitor_users', [
        'id', 'name', 'email', 'password', 'role', 'created_at', 'updated_at',
    ]));

    \Illuminate\Support\Facades\DB::table('monitor_users')->insert([
        'name' => 'Test User',
        'email' => 'test-user@example.com',
        'password' => 'irrelevant-for-this-test',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $row = \Illuminate\Support\Facades\DB::table('monitor_users')->where('email', 'test-user@example.com')->first();

    $this->assertSame('viewer', $row->role);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `/opt/homebrew/bin/php vendor/bin/phpunit --filter=test_monitor_users_table_exists_with_expected_columns`
Expected: FAIL — table `monitor_users` doesn't exist yet.

- [ ] **Step 3: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getConnection()
    {
        return config('monitor.storage.database.connection');
    }

    public function up(): void
    {
        Schema::create($this->usersTable(), function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('role', 16)->default('viewer');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->usersTable());
    }

    protected function usersTable(): string
    {
        return config('monitor.auth.table', 'monitor_users');
    }
};
```

- [ ] **Step 4: Add the `auth` config section**

In `config/monitor.php`, add this block right after the closing `],` of
the existing `'storage' => [...]` array (i.e. between the Storage Driver
section and the Retention section):

```php
    /*
    |--------------------------------------------------------------------------
    | Authentication
    |--------------------------------------------------------------------------
    |
    | The package's own login system for the dashboard — independent of the
    | host app's own Auth guards (Laravel isolates session state per guard
    | name automatically). `table` names the users table, auto-migrated
    | alongside the rest of the package's tables.
    |
    */

    'auth' => [
        'guard' => 'monitor',
        'table' => 'monitor_users',
    ],
```

- [ ] **Step 5: Run test to verify it passes**

Run: `/opt/homebrew/bin/php vendor/bin/phpunit --filter=test_monitor_users_table_exists_with_expected_columns`
Expected: PASS

- [ ] **Step 6: Run the full suite to check for regressions**

Run: `/opt/homebrew/bin/php vendor/bin/phpunit`
Expected: PASS, 46 tests (45 existing + 1 new).

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_07_19_000001_create_monitor_users_table.php config/monitor.php tests/MonitorTest.php
git commit -m "feat: add monitor_users table and auth config"
```

---

### Task 2: `MonitorUser` model

**Files:**
- Create: `src/Models/MonitorUser.php`
- Test: `tests/MonitorTest.php`

**Interfaces:**
- Consumes: `monitor_users` table (Task 1), `config('monitor.auth.table')`,
  `config('monitor.auth.guard')`, `config('monitor.storage.database.connection')`.
- Produces: `LaravelMonitor\Models\MonitorUser` — Eloquent model
  implementing `Authenticatable`/`Authorizable`/`CanResetPassword` (via
  extending `Illuminate\Foundation\Auth\User`), with an instance method
  `canManageSettings(): bool` (the only role check Foundation actually
  consumes — an `isOwner()` helper isn't added yet since nothing in this
  sub-project calls it; sub-project 2 adds it alongside transfer-ownership,
  when it has a real caller), and a static `guardName(): string` (reads
  `config('monitor.auth.guard', 'monitor')` — the single place every
  other task reads the configured guard name from, instead of repeating
  the `config()` call).

- [ ] **Step 1: Write the failing test**

Add to `tests/MonitorTest.php`:

```php
public function test_monitor_user_role_helpers_reflect_the_stored_role(): void
{
    $owner = \LaravelMonitor\Models\MonitorUser::create([
        'name' => 'Owner',
        'email' => 'owner-role-test@example.com',
        'password' => \Illuminate\Support\Facades\Hash::make('password'),
        'role' => 'owner',
    ]);
    $admin = \LaravelMonitor\Models\MonitorUser::create([
        'name' => 'Admin',
        'email' => 'admin-role-test@example.com',
        'password' => \Illuminate\Support\Facades\Hash::make('password'),
        'role' => 'admin',
    ]);
    $viewer = \LaravelMonitor\Models\MonitorUser::create([
        'name' => 'Viewer',
        'email' => 'viewer-role-test@example.com',
        'password' => \Illuminate\Support\Facades\Hash::make('password'),
        'role' => 'viewer',
    ]);

    $this->assertTrue($owner->canManageSettings());
    $this->assertTrue($admin->canManageSettings());
    $this->assertFalse($viewer->canManageSettings());

    $this->assertSame('monitor', \LaravelMonitor\Models\MonitorUser::guardName());
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `/opt/homebrew/bin/php vendor/bin/phpunit --filter=test_monitor_user_role_helpers_reflect_the_stored_role`
Expected: FAIL — class `LaravelMonitor\Models\MonitorUser` doesn't exist.

- [ ] **Step 3: Write the model**

```php
<?php

namespace LaravelMonitor\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * The package's own dashboard user — completely separate from the host
 * app's own User model. One flat table (no `teams` table): this package
 * supports exactly one team per installation, so `role` alone is enough
 * to express owner/admin/viewer.
 */
class MonitorUser extends Authenticatable
{
    protected $fillable = ['name', 'email', 'password', 'role'];

    protected $hidden = ['password'];

    public function getTable(): string
    {
        return config('monitor.auth.table', 'monitor_users');
    }

    public function getConnectionName(): ?string
    {
        return config('monitor.storage.database.connection');
    }

    public function canManageSettings(): bool
    {
        return in_array($this->role, ['owner', 'admin'], true);
    }

    public static function guardName(): string
    {
        return config('monitor.auth.guard', 'monitor');
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `/opt/homebrew/bin/php vendor/bin/phpunit --filter=test_monitor_user_role_helpers_reflect_the_stored_role`
Expected: PASS

- [ ] **Step 5: Run the full suite**

Run: `/opt/homebrew/bin/php vendor/bin/phpunit`
Expected: PASS, 47 tests.

- [ ] **Step 6: Commit**

```bash
git add src/Models/MonitorUser.php tests/MonitorTest.php
git commit -m "feat: add MonitorUser model with role helpers"
```

---

### Task 3: Register the `monitor` guard and provider

**Files:**
- Modify: `src/MonitorServiceProvider.php`
- Test: `tests/MonitorTest.php`

**Interfaces:**
- Consumes: `MonitorUser::class` (Task 2), `config('monitor.auth.guard')`.
- Produces: `config('auth.guards.monitor')` = `['driver' => 'session', 'provider' => 'monitor_users']`
  and `config('auth.providers.monitor_users')` = `['driver' => 'eloquent', 'model' => MonitorUser::class]`,
  set at boot time unless the host app already defined them (same
  "don't clobber a host override" idiom as the existing
  `registerAuthorization()` method's `Gate::has()` check) — so
  `Auth::guard(MonitorUser::guardName())` resolves to a working guard
  backed by `MonitorUser` from this point on.

- [ ] **Step 1: Write the failing test**

Add to `tests/MonitorTest.php`:

```php
public function test_the_monitor_guard_is_registered_and_backed_by_monitor_user(): void
{
    $user = \LaravelMonitor\Models\MonitorUser::create([
        'name' => 'Guard Test',
        'email' => 'guard-test@example.com',
        'password' => \Illuminate\Support\Facades\Hash::make('password'),
        'role' => 'owner',
    ]);

    $this->assertTrue(\Illuminate\Support\Facades\Auth::guard('monitor')->attempt([
        'email' => 'guard-test@example.com',
        'password' => 'password',
    ]));

    $this->assertTrue(\Illuminate\Support\Facades\Auth::guard('monitor')->check());
    $this->assertSame($user->id, \Illuminate\Support\Facades\Auth::guard('monitor')->id());
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `/opt/homebrew/bin/php vendor/bin/phpunit --filter=test_the_monitor_guard_is_registered_and_backed_by_monitor_user`
Expected: FAIL — `Auth::guard('monitor')` throws
`InvalidArgumentException: Auth guard [monitor] is not defined.`

- [ ] **Step 3: Register the guard in `MonitorServiceProvider`**

Add `use LaravelMonitor\Models\MonitorUser;` to the `use` block at the top
of `src/MonitorServiceProvider.php`.

In `register()`, add a call to a new `$this->registerAuth();` right after
the existing `$this->registerAuthorization();` line:

```php
        $this->registerLivewireComponents();
        $this->registerAuthorization();
        $this->registerAuth();
```

Add the new method right after `registerAuthorization()`:

```php
    /**
     * Register the package's own `monitor` guard/provider pair, unless the
     * host app already defined one under the same name — mirrors
     * registerAuthorization()'s "don't clobber a host override" rule for
     * the viewMonitor Gate.
     */
    protected function registerAuth(): void
    {
        $guard = MonitorUser::guardName();

        if (! $this->app['config']->has("auth.guards.{$guard}")) {
            $this->app['config']->set("auth.guards.{$guard}", [
                'driver' => 'session',
                'provider' => 'monitor_users',
            ]);
        }

        if (! $this->app['config']->has('auth.providers.monitor_users')) {
            $this->app['config']->set('auth.providers.monitor_users', [
                'driver' => 'eloquent',
                'model' => MonitorUser::class,
            ]);
        }
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `/opt/homebrew/bin/php vendor/bin/phpunit --filter=test_the_monitor_guard_is_registered_and_backed_by_monitor_user`
Expected: PASS

- [ ] **Step 5: Run the full suite**

Run: `/opt/homebrew/bin/php vendor/bin/phpunit`
Expected: PASS, 48 tests.

- [ ] **Step 6: Commit**

```bash
git add src/MonitorServiceProvider.php tests/MonitorTest.php
git commit -m "feat: register the monitor auth guard and provider"
```

---

### Task 4: Setup flow (first-run owner creation)

**Files:**
- Create: `src/Http/Controllers/Auth/SetupController.php`
- Create: `resources/views/auth/setup.blade.php`
- Modify: `routes/web.php`
- Test: `tests/MonitorTest.php`

**Interfaces:**
- Consumes: `MonitorUser` (Task 2), `MonitorUser::guardName()` (Task 2),
  the `monitor` guard (Task 3), `<x-monitor::layout>` (existing,
  `@props(['title'])`, renders `{{ $slot }}`).
- Produces: routes `monitor.setup` (`GET /monitor/setup`) and
  `monitor.setup.store` (`POST /monitor/setup`), both reachable without
  being authenticated (they're how you become authenticated) but still
  behind the existing `Authorize::class` Gate check, since that stays the
  outer, unconditional switch for the whole route group.

- [ ] **Step 1: Write the failing tests**

Add to `tests/MonitorTest.php`:

```php
public function test_setup_page_is_shown_when_no_users_exist(): void
{
    Gate::define('viewMonitor', fn ($user = null) => true);

    $this->get('/monitor/setup')
        ->assertOk()
        ->assertSeeText('Create the owner account');
}

public function test_setup_creates_the_first_user_as_owner_and_logs_them_in(): void
{
    Gate::define('viewMonitor', fn ($user = null) => true);

    $this->post('/monitor/setup', [
        'name' => 'First Owner',
        'email' => 'first-owner@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ])->assertRedirect('/monitor');

    $user = \LaravelMonitor\Models\MonitorUser::where('email', 'first-owner@example.com')->first();

    $this->assertNotNull($user);
    $this->assertSame('owner', $user->role);
    $this->assertTrue(\Illuminate\Support\Facades\Auth::guard('monitor')->check());
    $this->assertSame($user->id, \Illuminate\Support\Facades\Auth::guard('monitor')->id());
}

public function test_setup_is_unreachable_once_a_user_already_exists(): void
{
    Gate::define('viewMonitor', fn ($user = null) => true);

    \LaravelMonitor\Models\MonitorUser::create([
        'name' => 'Existing',
        'email' => 'existing@example.com',
        'password' => \Illuminate\Support\Facades\Hash::make('password'),
        'role' => 'owner',
    ]);

    $this->get('/monitor/setup')->assertRedirect('/monitor/login');

    $this->post('/monitor/setup', [
        'name' => 'Second Owner',
        'email' => 'second-owner@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ])->assertRedirect('/monitor/login');

    $this->assertNull(\LaravelMonitor\Models\MonitorUser::where('email', 'second-owner@example.com')->first());
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `/opt/homebrew/bin/php vendor/bin/phpunit --filter=test_setup`
Expected: FAIL — routes `/monitor/setup` don't exist yet (404s).

- [ ] **Step 3: Write `SetupController`**

```php
<?php

namespace LaravelMonitor\Http\Controllers\Auth;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use LaravelMonitor\Models\MonitorUser;

/**
 * First-run flow: when monitor_users is empty, the dashboard is
 * unreachable until someone creates the owner account here. That first
 * account always gets role=owner — every account created afterwards
 * (sub-project 2's invite flow) picks its role explicitly instead.
 */
class SetupController
{
    public function show(): View|RedirectResponse
    {
        if (MonitorUser::query()->exists()) {
            return redirect()->route('monitor.login');
        }

        return view('monitor::auth.setup');
    }

    public function store(Request $request): RedirectResponse
    {
        if (MonitorUser::query()->exists()) {
            return redirect()->route('monitor.login');
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $owner = MonitorUser::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'role' => 'owner',
        ]);

        Auth::guard(MonitorUser::guardName())->login($owner);

        return redirect()->route('monitor.dashboard');
    }
}
```

- [ ] **Step 4: Write the setup view**

```php
{{-- First-run flow: shown only while monitor_users is empty. See
     Http\Controllers\Auth\SetupController. --}}
<x-monitor::layout title="Set up">
    <div class="flex min-h-screen items-center justify-center bg-neutral-50 px-4 dark:bg-neutral-950">
        <div class="w-full max-w-sm">
            <div class="mb-6 flex items-center justify-center gap-2.5">
                <span class="flex h-9 w-9 items-center justify-center rounded-md bg-neutral-900 text-sm font-semibold text-white dark:bg-neutral-700">{{ strtoupper(mb_substr(config('app.name', 'L'), 0, 1)) }}</span>
                <span class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">{{ config('app.name', 'Laravel') }} Monitor</span>
            </div>

            <div class="rounded-lg border border-neutral-200 bg-white p-6 shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                <h1 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">Create the owner account</h1>
                <p class="mt-1 text-sm text-neutral-500 dark:text-neutral-400">This is the first sign-in — the account you create here becomes the owner.</p>

                @if ($errors->any())
                    <div class="mt-4 rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-600 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-400">
                        {{ $errors->first() }}
                    </div>
                @endif

                <form method="POST" action="{{ route('monitor.setup.store') }}" class="mt-4 space-y-4">
                    @csrf
                    <div>
                        <label for="name" class="block font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400">Name</label>
                        <input type="text" name="name" id="name" value="{{ old('name') }}" required autofocus
                               class="mt-1 w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-900 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100">
                    </div>
                    <div>
                        <label for="email" class="block font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400">Email</label>
                        <input type="email" name="email" id="email" value="{{ old('email') }}" required
                               class="mt-1 w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-900 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100">
                    </div>
                    <div>
                        <label for="password" class="block font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400">Password</label>
                        <input type="password" name="password" id="password" required
                               class="mt-1 w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-900 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100">
                    </div>
                    <div>
                        <label for="password_confirmation" class="block font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400">Confirm password</label>
                        <input type="password" name="password_confirmation" id="password_confirmation" required
                               class="mt-1 w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-900 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100">
                    </div>
                    <button type="submit" class="w-full rounded-md bg-blue-600 py-2 text-sm font-medium text-white hover:bg-blue-500">Create account</button>
                </form>
            </div>
        </div>
    </div>
</x-monitor::layout>
```

- [ ] **Step 5: Register the routes**

In `routes/web.php`, add `use LaravelMonitor\Http\Controllers\Auth\SetupController;`
to the `use` block, and add these two lines inside the existing
`Route::group()` closure, right after the opening `->group(function () {`
line (before the `RequestDetailController` route):

```php
        Route::get('/setup', [SetupController::class, 'show'])->name('monitor.setup');
        Route::post('/setup', [SetupController::class, 'store'])->name('monitor.setup.store');
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `/opt/homebrew/bin/php vendor/bin/phpunit --filter=test_setup`
Expected: PASS (all 3)

- [ ] **Step 7: Run the full suite**

Run: `/opt/homebrew/bin/php vendor/bin/phpunit`
Expected: PASS, 51 tests.

- [ ] **Step 8: Commit**

```bash
git add src/Http/Controllers/Auth/SetupController.php resources/views/auth/setup.blade.php routes/web.php tests/MonitorTest.php
git commit -m "feat: add the first-run owner setup flow"
```

---

### Task 5: Login flow

**Files:**
- Create: `src/Http/Controllers/Auth/LoginController.php`
- Create: `resources/views/auth/login.blade.php`
- Modify: `routes/web.php`
- Test: `tests/MonitorTest.php`

**Interfaces:**
- Consumes: `MonitorUser` / `MonitorUser::guardName()` (Task 2), the
  `monitor` guard (Task 3).
- Produces: routes `monitor.login` (`GET /monitor/login`),
  `monitor.login.store` (`POST /monitor/login`), `monitor.logout`
  (`POST /monitor/logout`) — same "reachable without auth, still behind
  the Gate" placement as Task 4's setup routes.

- [ ] **Step 1: Write the failing tests**

Add to `tests/MonitorTest.php`:

```php
public function test_login_page_is_shown(): void
{
    Gate::define('viewMonitor', fn ($user = null) => true);

    \LaravelMonitor\Models\MonitorUser::create([
        'name' => 'Existing',
        'email' => 'login-page-test@example.com',
        'password' => \Illuminate\Support\Facades\Hash::make('password'),
        'role' => 'owner',
    ]);

    $this->get('/monitor/login')
        ->assertOk()
        ->assertSeeText('Sign in');
}

public function test_login_with_correct_credentials_authenticates_and_redirects(): void
{
    Gate::define('viewMonitor', fn ($user = null) => true);

    $user = \LaravelMonitor\Models\MonitorUser::create([
        'name' => 'Login Success',
        'email' => 'login-success@example.com',
        'password' => \Illuminate\Support\Facades\Hash::make('correct-password'),
        'role' => 'admin',
    ]);

    $this->post('/monitor/login', [
        'email' => 'login-success@example.com',
        'password' => 'correct-password',
    ])->assertRedirect('/monitor');

    $this->assertSame($user->id, \Illuminate\Support\Facades\Auth::guard('monitor')->id());
}

public function test_login_with_wrong_password_does_not_authenticate(): void
{
    Gate::define('viewMonitor', fn ($user = null) => true);

    \LaravelMonitor\Models\MonitorUser::create([
        'name' => 'Login Failure',
        'email' => 'login-failure@example.com',
        'password' => \Illuminate\Support\Facades\Hash::make('correct-password'),
        'role' => 'admin',
    ]);

    $this->post('/monitor/login', [
        'email' => 'login-failure@example.com',
        'password' => 'wrong-password',
    ])->assertSessionHasErrors('email');

    $this->assertFalse(\Illuminate\Support\Facades\Auth::guard('monitor')->check());
}

public function test_logout_clears_the_monitor_guard_session(): void
{
    Gate::define('viewMonitor', fn ($user = null) => true);

    $user = \LaravelMonitor\Models\MonitorUser::create([
        'name' => 'Logout Test',
        'email' => 'logout-test@example.com',
        'password' => \Illuminate\Support\Facades\Hash::make('password'),
        'role' => 'owner',
    ]);

    $this->actingAs($user, 'monitor');
    $this->assertTrue(\Illuminate\Support\Facades\Auth::guard('monitor')->check());

    $this->post('/monitor/logout')->assertRedirect('/monitor/login');

    $this->assertFalse(\Illuminate\Support\Facades\Auth::guard('monitor')->check());
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `/opt/homebrew/bin/php vendor/bin/phpunit --filter=test_login|test_logout`
Expected: FAIL — routes don't exist yet.

- [ ] **Step 3: Write `LoginController`**

```php
<?php

namespace LaravelMonitor\Http\Controllers\Auth;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use LaravelMonitor\Models\MonitorUser;

class LoginController
{
    public function show(): View
    {
        return view('monitor::auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::guard(MonitorUser::guardName())->attempt($credentials)) {
            throw ValidationException::withMessages([
                'email' => 'These credentials do not match our records.',
            ]);
        }

        $request->session()->regenerate();

        return redirect()->route('monitor.dashboard');
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard(MonitorUser::guardName())->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('monitor.login');
    }
}
```

- [ ] **Step 4: Write the login view**

```php
{{-- Sign-in page. See Http\Controllers\Auth\LoginController. --}}
<x-monitor::layout title="Sign in">
    <div class="flex min-h-screen items-center justify-center bg-neutral-50 px-4 dark:bg-neutral-950">
        <div class="w-full max-w-sm">
            <div class="mb-6 flex items-center justify-center gap-2.5">
                <span class="flex h-9 w-9 items-center justify-center rounded-md bg-neutral-900 text-sm font-semibold text-white dark:bg-neutral-700">{{ strtoupper(mb_substr(config('app.name', 'L'), 0, 1)) }}</span>
                <span class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">{{ config('app.name', 'Laravel') }} Monitor</span>
            </div>

            <div class="rounded-lg border border-neutral-200 bg-white p-6 shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                <h1 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">Sign in</h1>
                <p class="mt-1 text-sm text-neutral-500 dark:text-neutral-400">Sign in to view the monitoring dashboard.</p>

                @if ($errors->any())
                    <div class="mt-4 rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-600 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-400">
                        {{ $errors->first() }}
                    </div>
                @endif

                <form method="POST" action="{{ route('monitor.login.store') }}" class="mt-4 space-y-4">
                    @csrf
                    <div>
                        <label for="email" class="block font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400">Email</label>
                        <input type="email" name="email" id="email" value="{{ old('email') }}" required autofocus
                               class="mt-1 w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-900 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100">
                    </div>
                    <div>
                        <label for="password" class="block font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400">Password</label>
                        <input type="password" name="password" id="password" required
                               class="mt-1 w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-900 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100">
                    </div>
                    <button type="submit" class="w-full rounded-md bg-blue-600 py-2 text-sm font-medium text-white hover:bg-blue-500">Sign in</button>
                </form>
            </div>
        </div>
    </div>
</x-monitor::layout>
```

- [ ] **Step 5: Register the routes**

In `routes/web.php`, add `use LaravelMonitor\Http\Controllers\Auth\LoginController;`
to the `use` block, and add these three lines right after Task 4's two
setup routes:

```php
        Route::get('/login', [LoginController::class, 'show'])->name('monitor.login');
        Route::post('/login', [LoginController::class, 'store'])->name('monitor.login.store');
        Route::post('/logout', [LoginController::class, 'destroy'])->name('monitor.logout');
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `/opt/homebrew/bin/php vendor/bin/phpunit --filter=test_login|test_logout`
Expected: PASS (all 4)

- [ ] **Step 7: Run the full suite**

Run: `/opt/homebrew/bin/php vendor/bin/phpunit`
Expected: PASS, 55 tests.

- [ ] **Step 8: Commit**

```bash
git add src/Http/Controllers/Auth/LoginController.php resources/views/auth/login.blade.php routes/web.php tests/MonitorTest.php
git commit -m "feat: add the login/logout flow"
```

---

### Task 6: Wire authentication into the live routes + keep the existing suite green

**Files:**
- Create: `src/Http/Middleware/EnsureMonitorAuthenticated.php`
- Modify: `routes/web.php`
- Modify: `tests/TestCase.php`
- Test: `tests/MonitorTest.php`

**Why this is one task, not two:** the moment the rest of the dashboard's
routes require a `monitor`-guard session, every one of the existing ~55
tests that hits `/monitor/...` without logging in through that guard
would start failing — and none of them are testing the auth system
itself, so rewriting each individually would be pure churn for an
orthogonal concern (this was surfaced and agreed during design — see the
"Impact on the existing test suite" section of
`docs/superpowers/specs/2026-07-19-user-auth-foundation-design.md`). The
fix (a default auto-login fixture in `TestCase`) has to land in the same
commit as the routes it's compensating for, or the suite is red in
between.

**Deviation from the design spec's wording, noted for transparency:** the
spec describes this as "`Authorize` middleware gains a second check."
This task instead adds a second, separate middleware
(`EnsureMonitorAuthenticated`) applied via an inner route group, leaving
`Authorize` untouched. Same end behavior (Gate checked first,
unconditionally, for every route; auth checked second, for every route
except setup/login/logout) — but doing it as two single-responsibility
middlewares avoids needing route-name special-casing logic inside one
middleware to skip the auth check for exactly three routes. If this
split isn't wanted, flag it — the alternative is straightforward to fold
back into `Authorize` itself.

**Interfaces:**
- Consumes: `MonitorUser` / `MonitorUser::guardName()` (Task 2), routes
  `monitor.setup`/`monitor.login` (Tasks 4-5).
- Produces: `EnsureMonitorAuthenticated` middleware; every existing
  protected route now requires a `monitor`-guard session;
  `TestCase::setUp()` seeds one `role = 'owner'` `MonitorUser` and calls
  `$this->actingAs($owner, MonitorUser::guardName())` by default; a new
  `TestCase::withoutMonitorAuth(): static` helper that logs the guard out
  again, for tests that need to exercise an unauthenticated state.

- [ ] **Step 1: Write the middleware**

```php
<?php

namespace LaravelMonitor\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use LaravelMonitor\Models\MonitorUser;

/**
 * Runs inside the existing Authorize (viewMonitor Gate) check, on every
 * route except setup/login/logout — those have to stay reachable while
 * unauthenticated, since they're how a visitor becomes authenticated.
 */
class EnsureMonitorAuthenticated
{
    public function handle(Request $request, Closure $next)
    {
        if (Auth::guard(MonitorUser::guardName())->check()) {
            return $next($request);
        }

        if (MonitorUser::query()->doesntExist()) {
            return redirect()->route('monitor.setup');
        }

        return redirect()->route('monitor.login');
    }
}
```

- [ ] **Step 2: Restructure `routes/web.php`**

Wrap every route that isn't setup/login/logout in an inner group carrying
the new middleware. The full file becomes:

```php
<?php

use Illuminate\Support\Facades\Route;
use LaravelMonitor\Http\Controllers\Auth\LoginController;
use LaravelMonitor\Http\Controllers\Auth\SetupController;
use LaravelMonitor\Http\Controllers\DashboardController;
use LaravelMonitor\Http\Controllers\JobAttemptController;
use LaravelMonitor\Http\Controllers\RequestDetailController;
use LaravelMonitor\Http\Controllers\SettingsController;
use LaravelMonitor\Http\Middleware\Authorize;
use LaravelMonitor\Http\Middleware\EnsureMonitorAuthenticated;

Route::domain(config('monitor.domain'))
    ->middleware(array_merge(config('monitor.middleware', ['web']), [Authorize::class]))
    ->prefix(config('monitor.path', 'monitor'))
    ->group(function () {
        Route::get('/setup', [SetupController::class, 'show'])->name('monitor.setup');
        Route::post('/setup', [SetupController::class, 'store'])->name('monitor.setup.store');
        Route::get('/login', [LoginController::class, 'show'])->name('monitor.login');
        Route::post('/login', [LoginController::class, 'store'])->name('monitor.login.store');
        Route::post('/logout', [LoginController::class, 'destroy'])->name('monitor.logout');

        Route::middleware(EnsureMonitorAuthenticated::class)->group(function () {
            Route::get('/requests/{requestId}', RequestDetailController::class)->name('monitor.requests.show');
            Route::get('/jobs/attempts/{attemptId}', JobAttemptController::class)->name('monitor.jobs.attempts.show');
            Route::post('/settings/system', [SettingsController::class, 'system'])->name('monitor.settings.system');
            Route::post('/settings/reset', [SettingsController::class, 'reset'])->name('monitor.settings.reset');
            Route::get('/{tab?}', DashboardController::class)->name('monitor.dashboard');
        });
    });
```

- [ ] **Step 3: Add the default auto-login fixture to `TestCase`**

Replace the full content of `tests/TestCase.php` with:

```php
<?php

namespace LaravelMonitor\Tests;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use LaravelMonitor\Contracts\Storage;
use LaravelMonitor\Models\MonitorUser;
use LaravelMonitor\Monitor;
use LaravelMonitor\MonitorServiceProvider;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * Keep tests hermetic: ignore any local .env (e.g. the demo-preview file)
     * so the environment comes solely from defineEnvironment().
     */
    protected $loadEnvironmentVariables = false;

    /**
     * The Queries recorder records every query regardless of duration or
     * context, so RefreshDatabase's own migration queries (run during
     * setUp, before the test body) get buffered too. Flush and purge them
     * here so each test starts from a clean monitor_entries table instead
     * of asserting against leftover framework-bootstrap noise.
     *
     * Also seeds and logs in a default `owner` MonitorUser: every existing
     * route now requires a monitor-guard session (see
     * EnsureMonitorAuthenticated), and almost no test in this suite is
     * actually testing the auth system itself — tests that need an
     * unauthenticated state call withoutMonitorAuth() to opt out, or log
     * in a differently-privileged user explicitly.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make(Monitor::class)->flush();
        $this->app->make(Storage::class)->purge();

        $owner = MonitorUser::create([
            'name' => 'Test Owner',
            'email' => 'owner@example.com',
            'password' => Hash::make('password'),
            'role' => 'owner',
        ]);

        $this->actingAs($owner, MonitorUser::guardName());
    }

    protected function withoutMonitorAuth(): static
    {
        Auth::guard(MonitorUser::guardName())->logout();

        return $this;
    }

    protected function getPackageProviders($app): array
    {
        return [
            LivewireServiceProvider::class,
            MonitorServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);

        // Belt-and-suspenders alongside MonitorServiceProvider::boot(): keep the dashboard
        // tests immune to Livewire's smart_wire_keys bug even if provider boot order changes.
        $app['config']->set('livewire.smart_wire_keys', false);
    }
}
```

- [ ] **Step 4: Update the setup/login/logout tests to opt out of the new default**

The tests added in Tasks 4 and 5 exercise the *unauthenticated* setup and
login flows, so they must call `$this->withoutMonitorAuth()` before
issuing their request, or `TestCase::setUp()`'s new default owner fixture
would already satisfy `EnsureMonitorAuthenticated` and the test would
never actually reach the code path it's meant to cover.

Two of Task 4's tests go further than that: they specifically assert
behavior for an *empty* `monitor_users` table, and `TestCase::setUp()`'s
default owner means that table is never actually empty by the time a
test body runs anymore — `SetupController::show()`/`store()` would see
`MonitorUser::query()->exists() === true` (the default owner) and
redirect to `/monitor/login` instead of behaving like a fresh install.
Those two additionally need `\LaravelMonitor\Models\MonitorUser::query()->delete();`
to restore the "no users yet" precondition the test is actually about.
The rest of Task 4/5's tests create their *own* `MonitorUser` rows with
hardcoded emails that don't collide with `owner@example.com`, and don't
depend on the table being empty, so they only need the
`withoutMonitorAuth()` call and nothing else.

In `tests/MonitorTest.php`, update these tests from Task 4:

```php
public function test_setup_page_is_shown_when_no_users_exist(): void
{
    Gate::define('viewMonitor', fn ($user = null) => true);
    $this->withoutMonitorAuth();
    \LaravelMonitor\Models\MonitorUser::query()->delete();

    $this->get('/monitor/setup')
        ->assertOk()
        ->assertSeeText('Create the owner account');
}
```

```php
public function test_setup_creates_the_first_user_as_owner_and_logs_them_in(): void
{
    Gate::define('viewMonitor', fn ($user = null) => true);
    $this->withoutMonitorAuth();
    \LaravelMonitor\Models\MonitorUser::query()->delete();

    $this->post('/monitor/setup', [
        'name' => 'First Owner',
        'email' => 'first-owner@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ])->assertRedirect('/monitor');

    $user = \LaravelMonitor\Models\MonitorUser::where('email', 'first-owner@example.com')->first();

    $this->assertNotNull($user);
    $this->assertSame('owner', $user->role);
    $this->assertTrue(\Illuminate\Support\Facades\Auth::guard('monitor')->check());
    $this->assertSame($user->id, \Illuminate\Support\Facades\Auth::guard('monitor')->id());
}
```

`test_setup_is_unreachable_once_a_user_already_exists` needs no change —
it's specifically testing the "a user already exists" branch, and
`TestCase::setUp()`'s default owner already puts it in exactly that
state, which is what the test wants.

Update these tests from Task 5:

```php
public function test_login_page_is_shown(): void
{
    Gate::define('viewMonitor', fn ($user = null) => true);
    $this->withoutMonitorAuth();

    \LaravelMonitor\Models\MonitorUser::create([
        'name' => 'Existing',
        'email' => 'login-page-test@example.com',
        'password' => \Illuminate\Support\Facades\Hash::make('password'),
        'role' => 'owner',
    ]);

    $this->get('/monitor/login')
        ->assertOk()
        ->assertSeeText('Sign in');
}
```

```php
public function test_login_with_correct_credentials_authenticates_and_redirects(): void
{
    Gate::define('viewMonitor', fn ($user = null) => true);
    $this->withoutMonitorAuth();

    $user = \LaravelMonitor\Models\MonitorUser::create([
        'name' => 'Login Success',
        'email' => 'login-success@example.com',
        'password' => \Illuminate\Support\Facades\Hash::make('correct-password'),
        'role' => 'admin',
    ]);

    $this->post('/monitor/login', [
        'email' => 'login-success@example.com',
        'password' => 'correct-password',
    ])->assertRedirect('/monitor');

    $this->assertSame($user->id, \Illuminate\Support\Facades\Auth::guard('monitor')->id());
}
```

```php
public function test_login_with_wrong_password_does_not_authenticate(): void
{
    Gate::define('viewMonitor', fn ($user = null) => true);
    $this->withoutMonitorAuth();

    \LaravelMonitor\Models\MonitorUser::create([
        'name' => 'Login Failure',
        'email' => 'login-failure@example.com',
        'password' => \Illuminate\Support\Facades\Hash::make('correct-password'),
        'role' => 'admin',
    ]);

    $this->post('/monitor/login', [
        'email' => 'login-failure@example.com',
        'password' => 'wrong-password',
    ])->assertSessionHasErrors('email');

    $this->assertFalse(\Illuminate\Support\Facades\Auth::guard('monitor')->check());
}
```

`test_logout_clears_the_monitor_guard_session` needs no change — it
already explicitly calls `$this->actingAs($user, 'monitor')` with its own
user before logging out, which correctly overrides `TestCase`'s default.

- [ ] **Step 5: Write the middleware-behavior tests**

Add to `tests/MonitorTest.php`:

```php
public function test_unauthenticated_visitor_is_redirected_to_setup_when_no_users_exist(): void
{
    Gate::define('viewMonitor', fn ($user = null) => true);
    $this->withoutMonitorAuth();
    \LaravelMonitor\Models\MonitorUser::query()->delete();

    $this->get('/monitor')->assertRedirect('/monitor/setup');
}

public function test_unauthenticated_visitor_is_redirected_to_login_when_users_exist(): void
{
    Gate::define('viewMonitor', fn ($user = null) => true);
    $this->withoutMonitorAuth();

    \LaravelMonitor\Models\MonitorUser::create([
        'name' => 'Existing',
        'email' => 'redirect-test@example.com',
        'password' => \Illuminate\Support\Facades\Hash::make('password'),
        'role' => 'owner',
    ]);

    $this->get('/monitor')->assertRedirect('/monitor/login');
}

public function test_authenticated_visitor_passes_through_to_the_dashboard(): void
{
    Gate::define('viewMonitor', fn ($user = null) => true);

    // TestCase::setUp() already logged in a default owner.
    $this->get('/monitor')->assertOk();
}

public function test_the_gate_still_hard_aborts_regardless_of_auth_state(): void
{
    Gate::define('viewMonitor', fn ($user = null) => false);

    // TestCase::setUp()'s default owner is authenticated, but the Gate
    // is the outer, unconditional switch — it must still win.
    $this->get('/monitor')->assertForbidden();
}
```

- [ ] **Step 6: Run the middleware and updated setup/login tests**

Run: `/opt/homebrew/bin/php vendor/bin/phpunit --filter=test_unauthenticated|test_authenticated|test_the_gate_still_hard_aborts|test_setup|test_login|test_logout`
Expected: PASS (all of them)

- [ ] **Step 7: Run the full suite**

Run: `/opt/homebrew/bin/php vendor/bin/phpunit`
Expected: PASS, 59 tests (55 + 4 new middleware tests), including every
pre-existing test from before this plan — proving the auto-login fixture
is transparent to everything that isn't specifically testing auth.

- [ ] **Step 8: Commit**

```bash
git add src/Http/Middleware/EnsureMonitorAuthenticated.php routes/web.php tests/TestCase.php tests/MonitorTest.php
git commit -m "feat: require monitor-guard authentication on every dashboard route"
```

---

### Task 7: Role enforcement on the Settings routes

**Files:**
- Modify: `src/Http/Controllers/SettingsController.php`
- Test: `tests/MonitorTest.php`

**Interfaces:**
- Consumes: `MonitorUser::canManageSettings()` / `MonitorUser::guardName()`
  (Task 2), `Request::user(?string $guard)` (Laravel core — resolves the
  authenticated user for the given guard).
- Produces: `POST /monitor/settings/system` and `POST /monitor/settings/reset`
  now `abort(403)` for a `viewer`-role user, before any validation runs.

- [ ] **Step 1: Write the failing tests**

Add to `tests/MonitorTest.php`:

```php
public function test_a_viewer_cannot_post_settings_system(): void
{
    Gate::define('viewMonitor', fn ($user = null) => true);

    $viewer = \LaravelMonitor\Models\MonitorUser::create([
        'name' => 'Viewer',
        'email' => 'settings-viewer@example.com',
        'password' => \Illuminate\Support\Facades\Hash::make('password'),
        'role' => 'viewer',
    ]);
    $this->actingAs($viewer, 'monitor');

    $this->post('/monitor/settings/system', [])->assertForbidden();
}

public function test_a_viewer_cannot_post_settings_reset(): void
{
    Gate::define('viewMonitor', fn ($user = null) => true);

    $viewer = \LaravelMonitor\Models\MonitorUser::create([
        'name' => 'Viewer',
        'email' => 'settings-reset-viewer@example.com',
        'password' => \Illuminate\Support\Facades\Hash::make('password'),
        'role' => 'viewer',
    ]);
    $this->actingAs($viewer, 'monitor');

    $this->post('/monitor/settings/reset')->assertForbidden();
}

public function test_an_admin_can_post_settings_reset(): void
{
    Gate::define('viewMonitor', fn ($user = null) => true);

    $admin = \LaravelMonitor\Models\MonitorUser::create([
        'name' => 'Admin',
        'email' => 'settings-admin@example.com',
        'password' => \Illuminate\Support\Facades\Hash::make('password'),
        'role' => 'admin',
    ]);
    $this->actingAs($admin, 'monitor');

    $this->post('/monitor/settings/reset')->assertRedirect();
}
```

(No equivalent "owner can" test is needed — `TestCase::setUp()`'s default
authenticated user is already an owner, so every other existing test that
happens to hit a settings route already covers that case for free; there
are none today, but the two new `viewer`/`admin` tests above are the
actual new coverage this task requires.)

- [ ] **Step 2: Run tests to verify they fail**

Run: `/opt/homebrew/bin/php vendor/bin/phpunit --filter=test_a_viewer_cannot|test_an_admin_can`
Expected: FAIL — `test_a_viewer_cannot_post_settings_system`/`_reset` get
a 422 (validation runs first) or redirect instead of 403, since there's
no permission check yet.

- [ ] **Step 3: Add the permission check to `SettingsController`**

Add `use LaravelMonitor\Models\MonitorUser;` to the `use` block at the top
of `src/Http/Controllers/SettingsController.php`.

At the very start of `system()`, before the existing `$validated = $request->validate([...`
line:

```php
    public function system(Request $request): RedirectResponse
    {
        abort_unless($request->user(MonitorUser::guardName())->canManageSettings(), 403);

        $validated = $request->validate([
```

Change `reset()`'s signature to accept the request, and add the same
check as its first line:

```php
    public function reset(Request $request): RedirectResponse
    {
        abort_unless($request->user(MonitorUser::guardName())->canManageSettings(), 403);

        Settings::reset();
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `/opt/homebrew/bin/php vendor/bin/phpunit --filter=test_a_viewer_cannot|test_an_admin_can`
Expected: PASS (all 3)

- [ ] **Step 5: Run the full suite**

Run: `/opt/homebrew/bin/php vendor/bin/phpunit`
Expected: PASS, 62 tests.

- [ ] **Step 6: Commit**

```bash
git add src/Http/Controllers/SettingsController.php tests/MonitorTest.php
git commit -m "feat: restrict settings changes to owner/admin roles"
```

---

### Task 8: Final verification

**Files:** none (verification only)

- [ ] **Step 1: Run the full suite one more time from a clean state**

Run: `/opt/homebrew/bin/php vendor/bin/phpunit`
Expected: PASS, 62 tests, 0 failures, pristine output (no warnings).

- [ ] **Step 2: Syntax-check every new Blade view**

Run: `/opt/homebrew/bin/php -l resources/views/auth/setup.blade.php && /opt/homebrew/bin/php -l resources/views/auth/login.blade.php`
Expected: `No syntax errors detected` for both.

- [ ] **Step 3: Manual smoke check (optional but recommended)**

If a local consuming app is available (per this session's established
pattern — see `CLAUDE.local.md`), migrate the new table and click through
the flow by hand: visiting `/monitor` with an empty `monitor_users` table
redirects to `/monitor/setup`; creating the owner logs straight into the
dashboard; logging out and back in works; a `viewer`-role user (create
one manually via tinker, since there's no invite UI yet — that's
sub-project 2) sees the dashboard but gets a 403 POSTing the settings
form.

- [ ] **Step 4: Report status**

This closes sub-project 1/4 (Foundation) of the user/team system. Do not
push or open a PR unless the user explicitly asks — this is a new
feature branch (`feat/team-auth-management`) with no PR yet, unlike the
already-open PR #13 this session was also working from. Report the final
test count and ask whether to proceed to sub-project 2 (Team & Members)
or push/open a PR first.
