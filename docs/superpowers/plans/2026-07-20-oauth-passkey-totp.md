# OAuth/Passkey/TOTP Login Methods Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add three independent, opt-in login methods to `laravel-monitor`'s existing `monitor` guard — TOTP (as a second factor after password), Passkey/WebAuthn (standalone passwordless), and OAuth via Google + Apple (standalone passwordless, existing accounts only) — built in that order.

**Architecture:** All three plug into the existing session-based `monitor` guard without changing `Authorize`/`EnsureMonitorAuthenticated` middleware. TOTP inserts a "partial-auth" challenge step between password-check and `Auth::guard('monitor')->login()`. Passkey and OAuth call `login()` directly on success (they already combine two factors on their own). All three ship as optional dependencies (`suggest`, mirrored in `require-dev` so this repo's own test suite exercises them) behind a single `OptionalAuthMethod` availability helper, so a consuming app that doesn't `composer require` the relevant library sees a disabled card with an install hint instead of an error.

**Tech Stack:** `pragmarx/google2fa` + `bacon/bacon-qr-code` (TOTP), `web-auth/webauthn-lib` (Passkey), `laravel/socialite` + `socialiteproviders/apple` (OAuth).

## Global Constraints

- **Single migration file, always.** Every new column/table in this plan is added to the existing `database/migrations/2026_01_01_000000_create_monitor_table.php` — never a new migration file (see `AGENTS.md`).
- **New composer dependencies are optional.** `pragmarx/google2fa`, `bacon/bacon-qr-code`, `web-auth/webauthn-lib`, `laravel/socialite`, `socialiteproviders/apple` go in both `suggest` (for consuming apps) and `require-dev` (so this repo's own `composer test` exercises the real libraries) — never in `require`.
- **TOTP is a second factor, not a replacement for password.** A TOTP-enabled user still submits email+password first; a correct 6-digit code or recovery code is a required second step before `Auth::guard('monitor')->login()` is called.
- **Passkey and OAuth are standalone passwordless logins.** A successful WebAuthn ceremony or OAuth callback calls `Auth::guard('monitor')->login()` directly — they never trigger the TOTP challenge, even for a user who has TOTP enabled.
- **OAuth never creates a `MonitorUser`.** The callback only authenticates an existing row matched by the provider's verified email; no match means a clear error and no database write.
- **Disabled+note UI, not hidden UI.** When `OptionalAuthMethod::*Available()` is false, the relevant card/button still renders, visibly disabled, with a one-line note naming the exact `composer require` command — never a silently missing feature.
- **Config keys live under `monitor.auth.*`** in `config/monitor.php`, following the existing pattern (`auth.table`, `auth.invitations_table`, etc.) — configurable table names, `env()`-backed OAuth credentials.
- Run `/opt/homebrew/bin/php vendor/bin/phpunit` (all tasks) and `/opt/homebrew/bin/php -l <file>` (any modified `.blade.php`) exactly as prior sub-projects did — this repo's `composer test` script is `phpunit`.

---

### Task 1: Schema, config, optional-dependency plumbing

**Files:**
- Modify: `database/migrations/2026_01_01_000000_create_monitor_table.php`
- Modify: `config/monitor.php`
- Modify: `composer.json`
- Modify: `src/Models/MonitorUser.php`
- Create: `src/Support/OptionalAuthMethod.php`
- Create: `src/Models/MonitorWebauthnCredential.php`
- Create: `src/Models/MonitorOauthAccount.php`
- Test: `tests/OptionalAuthMethodTest.php`

**Interfaces:**
- Produces: `MonitorUser::hasTotpEnabled(): bool`; new nullable columns `totp_secret`, `totp_enabled_at`, `totp_recovery_codes` on `monitor_users`; new tables `monitor_webauthn_credentials` and `monitor_oauth_accounts` with Eloquent models `MonitorWebauthnCredential`/`MonitorOauthAccount`; `LaravelMonitor\Support\OptionalAuthMethod::totpAvailable(): bool`, `::passkeysAvailable(): bool`, `::oauthAvailable(string $provider): bool` — every later task's controllers and views call these.

- [ ] **Step 1: Add the TOTP columns and two new tables to the single migration file**

Open `database/migrations/2026_01_01_000000_create_monitor_table.php`. In the `usersTable()` `Schema::create` block, add three columns right after `role`:

```php
            $table->string('role', 16)->default('viewer');
            $table->text('totp_secret')->nullable();
            $table->timestamp('totp_enabled_at')->nullable();
            $table->json('totp_recovery_codes')->nullable();
            $table->timestamps();
```

(Remove the old bare `$table->timestamps();` line that followed `role` — it's now the line right after the three new columns, not duplicated.)

After the `emailChangesTable()` `Schema::create` block (the last one in `up()`, right before the closing brace of `up()`), add:

```php

        Schema::create($this->webauthnCredentialsTable(), function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('credential_id')->unique();
            $table->text('public_key');
            $table->string('label');
            $table->unsignedInteger('sign_count')->default(0);
            $table->timestamp('created_at');

            $table->index('user_id');
        });

        Schema::create($this->oauthAccountsTable(), function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('provider', 16);
            $table->string('provider_user_id');
            $table->timestamps();

            $table->unique(['provider', 'provider_user_id']);
            $table->index('user_id');
        });
```

In `down()`, add the two new drops **before** the existing ones (reverse creation order):

```php
    public function down(): void
    {
        Schema::dropIfExists($this->oauthAccountsTable());
        Schema::dropIfExists($this->webauthnCredentialsTable());
        Schema::dropIfExists($this->emailChangesTable());
```

Add the two new table-name helper methods, next to the existing ones (e.g. after `emailChangesTable()`):

```php
    protected function webauthnCredentialsTable(): string
    {
        return config('monitor.auth.webauthn_table', 'monitor_webauthn_credentials');
    }

    protected function oauthAccountsTable(): string
    {
        return config('monitor.auth.oauth_accounts_table', 'monitor_oauth_accounts');
    }
```

- [ ] **Step 2: Add the new config keys**

In `config/monitor.php`, inside the `'auth' => [...]` array (next to `'email_changes_table' => 'monitor_email_changes',`), add:

```php
        'webauthn_table' => 'monitor_webauthn_credentials',
        'oauth_accounts_table' => 'monitor_oauth_accounts',
        'oauth' => [
            'google' => [
                'client_id' => env('MONITOR_GOOGLE_CLIENT_ID'),
                'client_secret' => env('MONITOR_GOOGLE_CLIENT_SECRET'),
                'redirect' => env('MONITOR_GOOGLE_REDIRECT_URI'),
            ],
            'apple' => [
                'client_id' => env('MONITOR_APPLE_CLIENT_ID'),
                'client_secret' => env('MONITOR_APPLE_CLIENT_SECRET'),
                'key_id' => env('MONITOR_APPLE_KEY_ID'),
                'team_id' => env('MONITOR_APPLE_TEAM_ID'),
                'private_key' => env('MONITOR_APPLE_PRIVATE_KEY'),
                'redirect' => env('MONITOR_APPLE_REDIRECT_URI'),
            ],
        ],
```

- [ ] **Step 3: Mark the five packages as optional dependencies**

In `composer.json`, add to `require-dev` (so this repo's own test suite exercises real code against them):

```json
        "orchestra/testbench": "^8.36|^9.15|^10.8|^11.0",
        "phpunit/phpunit": "^10.0|^11.0|^12.0|^13.0",
        "pragmarx/google2fa": "^8.0",
        "bacon/bacon-qr-code": "^3.0",
        "web-auth/webauthn-lib": "^4.0|^5.0",
        "laravel/socialite": "^5.0",
        "socialiteproviders/apple": "^5.0"
```

Add a new top-level `"suggest"` key (so consuming apps that never `composer require` these see the same list via `composer show ntm-dev/laravel-monitor`):

```json
    "suggest": {
        "pragmarx/google2fa": "Required to enable TOTP two-factor authentication (also requires bacon/bacon-qr-code).",
        "bacon/bacon-qr-code": "Renders the TOTP enrollment QR code as SVG.",
        "web-auth/webauthn-lib": "Required to enable Passkey (WebAuthn) login.",
        "laravel/socialite": "Required to enable OAuth login (Google/Apple).",
        "socialiteproviders/apple": "Required in addition to laravel/socialite to enable Sign in with Apple."
    },
```

Place it after `"require-dev"` and before `"autoload"`, matching standard composer.json key ordering.

- [ ] **Step 4: Update `MonitorUser`**

Modify `src/Models/MonitorUser.php`:

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
    protected $fillable = ['name', 'email', 'password', 'role', 'totp_secret', 'totp_enabled_at', 'totp_recovery_codes'];

    protected $hidden = ['password', 'totp_secret', 'totp_recovery_codes'];

    protected $casts = [
        'totp_enabled_at' => 'datetime',
        'totp_secret' => 'encrypted',
        'totp_recovery_codes' => 'array',
    ];

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

    public function isOwner(): bool
    {
        return $this->role === 'owner';
    }

    public function canManageTeam(): bool
    {
        return in_array($this->role, ['owner', 'admin'], true);
    }

    public function hasTotpEnabled(): bool
    {
        return $this->totp_enabled_at !== null;
    }

    public static function guardName(): string
    {
        return config('monitor.auth.guard', 'monitor');
    }
}
```

- [ ] **Step 5: Create the two new Eloquent models**

Create `src/Models/MonitorWebauthnCredential.php`:

```php
<?php

namespace LaravelMonitor\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MonitorWebauthnCredential extends Model
{
    public $timestamps = false;

    protected $fillable = ['user_id', 'credential_id', 'public_key', 'label', 'sign_count'];

    public function getTable(): string
    {
        return config('monitor.auth.webauthn_table', 'monitor_webauthn_credentials');
    }

    public function getConnectionName(): ?string
    {
        return config('monitor.storage.database.connection');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(MonitorUser::class, 'user_id');
    }
}
```

Create `src/Models/MonitorOauthAccount.php`:

```php
<?php

namespace LaravelMonitor\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MonitorOauthAccount extends Model
{
    protected $fillable = ['user_id', 'provider', 'provider_user_id'];

    public function getTable(): string
    {
        return config('monitor.auth.oauth_accounts_table', 'monitor_oauth_accounts');
    }

    public function getConnectionName(): ?string
    {
        return config('monitor.storage.database.connection');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(MonitorUser::class, 'user_id');
    }
}
```

- [ ] **Step 6: Create `OptionalAuthMethod`**

Create `src/Support/OptionalAuthMethod.php`:

```php
<?php

namespace LaravelMonitor\Support;

class OptionalAuthMethod
{
    public static function totpAvailable(): bool
    {
        return class_exists(\PragmaRX\Google2FA\Google2FA::class)
            && class_exists(\BaconQrCode\Writer::class);
    }

    public static function passkeysAvailable(): bool
    {
        return class_exists(\Webauthn\Server::class);
    }

    public static function oauthAvailable(string $provider): bool
    {
        if (! class_exists(\Laravel\Socialite\Facades\Socialite::class)) {
            return false;
        }

        if ($provider === 'apple' && ! class_exists(\SocialiteProviders\Apple\Provider::class)) {
            return false;
        }

        $config = config("monitor.auth.oauth.{$provider}", []);

        return filled($config['client_id'] ?? null);
    }
}
```

- [ ] **Step 7: Write and run the tests**

Create `tests/OptionalAuthMethodTest.php`:

```php
<?php

namespace LaravelMonitor\Tests;

use LaravelMonitor\Support\OptionalAuthMethod;

class OptionalAuthMethodTest extends TestCase
{
    public function test_totp_is_available_when_google2fa_and_bacon_qr_code_are_installed(): void
    {
        $this->assertTrue(OptionalAuthMethod::totpAvailable());
    }

    public function test_passkeys_are_available_when_webauthn_lib_is_installed(): void
    {
        $this->assertTrue(OptionalAuthMethod::passkeysAvailable());
    }

    public function test_oauth_is_unavailable_for_a_provider_with_no_configured_client_id(): void
    {
        config(['monitor.auth.oauth.google.client_id' => null]);

        $this->assertFalse(OptionalAuthMethod::oauthAvailable('google'));
    }

    public function test_oauth_is_available_for_google_once_a_client_id_is_configured(): void
    {
        config(['monitor.auth.oauth.google.client_id' => 'test-client-id']);

        $this->assertTrue(OptionalAuthMethod::oauthAvailable('google'));
    }

    public function test_oauth_for_apple_also_requires_the_socialiteproviders_apple_package(): void
    {
        config(['monitor.auth.oauth.apple.client_id' => 'test-client-id']);

        $this->assertTrue(OptionalAuthMethod::oauthAvailable('apple'));
    }
}
```

Run: `/opt/homebrew/bin/php vendor/bin/phpunit --filter=OptionalAuthMethodTest`
Expected: 5 passing tests (all five optional packages are present via `require-dev`, so every `*Available()` check is `true` once configured).

Run: `/opt/homebrew/bin/php vendor/bin/phpunit`
Expected: full suite passes, 125 + 5 = 130 tests. (`monitor_users` gained 3 nullable columns and two nullable-by-default new tables — no existing test touches them, so nothing else should change.)

- [ ] **Step 8: Commit**

```bash
git add database/migrations/2026_01_01_000000_create_monitor_table.php config/monitor.php composer.json composer.lock src/Models/MonitorUser.php src/Support/OptionalAuthMethod.php src/Models/MonitorWebauthnCredential.php src/Models/MonitorOauthAccount.php tests/OptionalAuthMethodTest.php
git commit -m "feat: add schema, config, and optional-dependency plumbing for TOTP/Passkey/OAuth"
```

Run `composer update pragmarx/google2fa bacon/bacon-qr-code web-auth/webauthn-lib laravel/socialite socialiteproviders/apple` (or `composer install`) before running tests, so `composer.lock` reflects the new `require-dev` entries and is staged in the same commit.

---

### Task 2: TOTP enrollment

**Files:**
- Modify: `src/Livewire/Team.php`
- Modify: `resources/views/livewire/team.blade.php`
- Test: `tests/TwoFactorTest.php`

**Interfaces:**
- Consumes: `MonitorUser::hasTotpEnabled()`, `OptionalAuthMethod::totpAvailable()` (Task 1).
- Produces: `Team::totpSecret` public property (the pending, unconfirmed secret, kept in the Livewire component's own state across the two-step enroll — not persisted until confirmed), `Team::startEnrollingTotp(): void`, `Team::confirmTotp(string $code): void`. Task 3 (login challenge) and Task 4 (disable) both read `$user->totp_secret`/`totp_enabled_at`/`totp_recovery_codes` directly — no new consumer-facing method beyond what's here.

- [ ] **Step 1: Write the failing tests**

Add to `tests/TwoFactorTest.php` (new file):

```php
<?php

namespace LaravelMonitor\Tests;

use Illuminate\Support\Facades\Hash;
use LaravelMonitor\Livewire\Team;
use LaravelMonitor\Models\MonitorUser;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;

class TwoFactorTest extends TestCase
{
    public function test_starting_enrollment_generates_a_secret_but_does_not_persist_it_yet(): void
    {
        $owner = $this->actingAsOwner();

        Livewire::test(Team::class)->call('startEnrollingTotp');

        $this->assertNull($owner->refresh()->totp_secret);
    }

    public function test_confirming_with_the_correct_code_enables_totp_and_generates_recovery_codes(): void
    {
        $owner = $this->actingAsOwner();
        $google2fa = new Google2FA();

        $component = Livewire::test(Team::class)->call('startEnrollingTotp');
        $secret = $component->get('totpSecret');

        $component->call('confirmTotp', $google2fa->getCurrentOtp($secret));

        $owner->refresh();
        $this->assertNotNull($owner->totp_secret);
        $this->assertNotNull($owner->totp_enabled_at);
        $this->assertCount(8, $owner->totp_recovery_codes);
    }

    public function test_confirming_with_a_wrong_code_enables_nothing(): void
    {
        $owner = $this->actingAsOwner();

        $component = Livewire::test(Team::class)->call('startEnrollingTotp');
        $component->call('confirmTotp', '000000');

        $owner->refresh();
        $this->assertNull($owner->totp_secret);
        $this->assertNull($owner->totp_enabled_at);
    }

    protected function actingAsOwner(): MonitorUser
    {
        // TestCase::setUp() already created and logged in "Test Owner" — reuse it directly.
        return MonitorUser::query()->where('email', 'owner@example.com')->firstOrFail();
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `/opt/homebrew/bin/php vendor/bin/phpunit --filter=TwoFactorTest`
Expected: FAIL — `startEnrollingTotp`/`confirmTotp` don't exist on `Team` yet.

- [ ] **Step 3: Add the enrollment methods to `Team`**

In `src/Livewire/Team.php`, add near the top of the class (after the `use` imports, before `view()`):

```php
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use LaravelMonitor\Support\OptionalAuthMethod;
use PragmaRX\Google2FA\Google2FA;
```

Add a public property and the two methods (anywhere among the other public methods, e.g. after `rejectEmailChange()`):

```php
    public ?string $totpSecret = null;

    public function startEnrollingTotp(): void
    {
        if (! OptionalAuthMethod::totpAvailable()) {
            return;
        }

        $this->totpSecret = (new Google2FA())->generateSecretKey();
    }

    public function confirmTotp(string $code): void
    {
        if ($this->totpSecret === null) {
            return;
        }

        $google2fa = new Google2FA();

        if (! $google2fa->verifyKey($this->totpSecret, $code)) {
            $this->addError('totp', 'That code did not match. Please try again.');

            return;
        }

        $actor = $this->actor();
        $recoveryCodes = collect(range(1, 8))
            ->map(fn () => Str::upper(Str::random(10)))
            ->values();

        $actor->update([
            'totp_secret' => $this->totpSecret,
            'totp_enabled_at' => now(),
            'totp_recovery_codes' => $recoveryCodes->map(fn (string $code) => Hash::make($code))->all(),
        ]);

        $this->totpSecret = null;
        $this->dispatch('totp-enabled', recoveryCodes: $recoveryCodes->all());
    }
```

Note: `$this->dispatch('totp-enabled', recoveryCodes: ...)` is a Livewire browser event carrying the plain (unhashed) codes for one-time display — the component's own state never stores plaintext codes, only the hashes already saved to `$actor`.

- [ ] **Step 4: Add the QR/enroll UI to `team.blade.php`**

In `resources/views/livewire/team.blade.php`, immediately after the "Change your email" `</x-monitor::card>` (before the `@if ($pendingInvitations->isNotEmpty())` block), add:

```blade
        <x-monitor::card class="p-4">
            <p class="font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400">Two-factor authentication</p>
            @if (! \LaravelMonitor\Support\OptionalAuthMethod::totpAvailable())
                <p class="mt-2 text-sm text-neutral-400 dark:text-neutral-500">Install <code class="font-mono text-xs">pragmarx/google2fa bacon/bacon-qr-code</code> to enable this.</p>
            @elseif ($actor->hasTotpEnabled())
                <p class="mt-2 text-sm text-neutral-500 dark:text-neutral-400">Enabled for your account.</p>
            @elseif ($totpSecret === null)
                <button type="button" wire:click="startEnrollingTotp" class="mt-3 h-8 rounded-md bg-blue-600 px-3 text-sm font-medium text-white hover:bg-blue-500">Enable</button>
            @else
                <div class="mt-3">
                    {!! (new BaconQrCode\Writer(new BaconQrCode\Renderer\ImageRenderer(new BaconQrCode\Renderer\RendererStyle\RendererStyle(200), new BaconQrCode\Renderer\Image\SvgImageBackEnd())))->writeString((new PragmaRX\Google2FA\Google2FA())->getQRCodeUrl(config('app.name', 'Laravel'), $actor->email, $totpSecret)) !!}
                    <p class="mt-2 font-mono text-xs text-neutral-500 dark:text-neutral-400">{{ $totpSecret }}</p>
                </div>
                <form wire:submit="confirmTotp($refs.totpCode.value)" class="mt-3 flex flex-wrap items-end gap-2" x-data>
                    <div class="min-w-0 flex-1">
                        <label class="block font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400">Enter the 6-digit code</label>
                        <input type="text" x-ref="totpCode" required inputmode="numeric" pattern="[0-9]*" maxlength="6"
                               class="mt-1 w-full rounded-md border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 px-2 py-1.5 text-sm text-neutral-900 dark:text-neutral-100 focus:outline-none">
                    </div>
                    <button type="submit" class="h-8 rounded-md bg-blue-600 px-3 text-sm font-medium text-white hover:bg-blue-500">Confirm</button>
                </form>
                @error('totp')
                    <p class="mt-2 text-sm text-rose-600 dark:text-rose-400">{{ $message }}</p>
                @enderror
            @endif
        </x-monitor::card>

        <div x-data="{ codes: null }" x-on:totp-enabled.window="codes = $event.detail.recoveryCodes" x-show="codes" x-cloak>
            <x-monitor::card class="mt-4 p-4">
                <p class="font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400">Recovery codes — save these now, they won't be shown again</p>
                <ul class="mt-3 grid grid-cols-2 gap-1 font-mono text-sm text-neutral-700 dark:text-neutral-200">
                    <template x-for="code in codes" :key="code">
                        <li x-text="code"></li>
                    </template>
                </ul>
            </x-monitor::card>
        </div>
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `/opt/homebrew/bin/php vendor/bin/phpunit --filter=TwoFactorTest`
Expected: PASS.

- [ ] **Step 6: Syntax-check the view and run the full suite**

Run: `/opt/homebrew/bin/php -l resources/views/livewire/team.blade.php`
Expected: `No syntax errors detected`.

Run: `/opt/homebrew/bin/php vendor/bin/phpunit`
Expected: PASS, 133 tests (130 + 3 new).

- [ ] **Step 7: Commit**

```bash
git add src/Livewire/Team.php resources/views/livewire/team.blade.php tests/TwoFactorTest.php
git commit -m "feat: add TOTP enrollment to the Team view"
```

---

### Task 3: TOTP login challenge

**Files:**
- Modify: `src/Http/Controllers/Auth/LoginController.php`
- Create: `src/Http/Controllers/Auth/TwoFactorChallengeController.php`
- Create: `resources/views/auth/two-factor-challenge.blade.php`
- Modify: `routes/web.php`
- Modify (append to): `tests/TwoFactorTest.php`

**Interfaces:**
- Consumes: `MonitorUser::hasTotpEnabled()`, `google2fa->verifyKey()` pattern from Task 2.
- Produces: session key `monitor_2fa_challenge_user_id` (set by `LoginController::store()`, consumed and cleared by `TwoFactorChallengeController`) — this is the "partial-auth" state the design spec requires; no other task reads or writes it.

- [ ] **Step 1: Write the failing tests**

Append to `tests/TwoFactorTest.php`:

```php
    public function test_logging_in_a_totp_enabled_user_redirects_to_the_challenge_instead_of_the_dashboard(): void
    {
        Gate::define('viewMonitor', fn ($user = null) => true);
        $this->withoutMonitorAuth();
        $user = $this->createTotpEnabledUser();

        $this->post('/monitor/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect('/monitor/two-factor-challenge');

        $this->assertFalse(Auth::guard('monitor')->check());
    }

    public function test_a_correct_totp_code_on_the_challenge_completes_login(): void
    {
        Gate::define('viewMonitor', fn ($user = null) => true);
        $this->withoutMonitorAuth();
        $user = $this->createTotpEnabledUser();

        $this->post('/monitor/login', ['email' => $user->email, 'password' => 'password']);

        $code = (new Google2FA())->getCurrentOtp(decrypt($user->getRawOriginal('totp_secret')));

        $this->post('/monitor/two-factor-challenge', ['code' => $code])
            ->assertRedirect('/monitor');

        $this->assertTrue(Auth::guard('monitor')->check());
        $this->assertSame($user->id, Auth::guard('monitor')->id());
    }

    public function test_a_wrong_totp_code_on_the_challenge_does_not_log_in(): void
    {
        Gate::define('viewMonitor', fn ($user = null) => true);
        $this->withoutMonitorAuth();
        $user = $this->createTotpEnabledUser();

        $this->post('/monitor/login', ['email' => $user->email, 'password' => 'password']);

        $this->post('/monitor/two-factor-challenge', ['code' => '000000'])
            ->assertSessionHasErrors('code');

        $this->assertFalse(Auth::guard('monitor')->check());
    }

    public function test_a_correct_recovery_code_logs_in_and_is_removed_from_the_stored_list(): void
    {
        Gate::define('viewMonitor', fn ($user = null) => true);
        $this->withoutMonitorAuth();
        $user = $this->createTotpEnabledUser();
        $plainRecoveryCode = 'RECOVERY01';
        $user->update(['totp_recovery_codes' => [Hash::make($plainRecoveryCode), Hash::make('OTHERCODE')]]);

        $this->post('/monitor/login', ['email' => $user->email, 'password' => 'password']);

        $this->post('/monitor/two-factor-challenge', ['code' => $plainRecoveryCode])
            ->assertRedirect('/monitor');

        $this->assertTrue(Auth::guard('monitor')->check());
        $this->assertCount(1, $user->refresh()->totp_recovery_codes);
    }

    public function test_reusing_a_spent_recovery_code_fails(): void
    {
        Gate::define('viewMonitor', fn ($user = null) => true);
        $this->withoutMonitorAuth();
        $user = $this->createTotpEnabledUser();
        $user->update(['totp_recovery_codes' => [Hash::make('ONETIME01')]]);

        $this->post('/monitor/login', ['email' => $user->email, 'password' => 'password']);
        $this->post('/monitor/two-factor-challenge', ['code' => 'ONETIME01']);
        $this->withoutMonitorAuth();

        $this->post('/monitor/login', ['email' => $user->email, 'password' => 'password']);
        $this->post('/monitor/two-factor-challenge', ['code' => 'ONETIME01'])
            ->assertSessionHasErrors('code');

        $this->assertFalse(Auth::guard('monitor')->check());
    }

    protected function createTotpEnabledUser(): MonitorUser
    {
        return MonitorUser::create([
            'name' => 'Totp User',
            'email' => 'totp-user@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'totp_secret' => (new Google2FA())->generateSecretKey(),
            'totp_enabled_at' => now(),
            'totp_recovery_codes' => [],
        ]);
    }
```

Add the missing imports at the top of `tests/TwoFactorTest.php`:

```php
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `/opt/homebrew/bin/php vendor/bin/phpunit --filter=TwoFactorTest`
Expected: FAIL — `/monitor/two-factor-challenge` doesn't exist yet (404), and `LoginController::store()` still logs a TOTP-enabled user straight in.

- [ ] **Step 3: Make `LoginController::store()` divert to the challenge**

Replace `src/Http/Controllers/Auth/LoginController.php`'s `store()` method:

```php
    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = MonitorUser::query()->where('email', $credentials['email'])->first();

        if ($user === null || ! Auth::guard(MonitorUser::guardName())->validate($credentials)) {
            throw ValidationException::withMessages([
                'email' => 'These credentials do not match our records.',
            ]);
        }

        if ($user->hasTotpEnabled()) {
            $request->session()->put('monitor_2fa_challenge_user_id', $user->id);

            return redirect()->route('monitor.two-factor.challenge');
        }

        Auth::guard(MonitorUser::guardName())->login($user);
        $request->session()->regenerate();

        return redirect()->route('monitor.dashboard');
    }
```

(`attempt()` both validates credentials AND logs in on success in one call — since a TOTP user must NOT be logged in yet, this splits it into `validate()` (checks credentials, establishes nothing) followed by an explicit `login()` only on the non-TOTP branch.)

- [ ] **Step 4: Create `TwoFactorChallengeController`**

Create `src/Http/Controllers/Auth/TwoFactorChallengeController.php`:

```php
<?php

namespace LaravelMonitor\Http\Controllers\Auth;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use LaravelMonitor\Models\MonitorUser;
use PragmaRX\Google2FA\Google2FA;

class TwoFactorChallengeController
{
    public function show(Request $request): View|RedirectResponse
    {
        if ($request->session()->get('monitor_2fa_challenge_user_id') === null) {
            return redirect()->route('monitor.login');
        }

        return view('monitor::auth.two-factor-challenge');
    }

    public function store(Request $request): RedirectResponse
    {
        $userId = $request->session()->get('monitor_2fa_challenge_user_id');

        if ($userId === null) {
            return redirect()->route('monitor.login');
        }

        $validated = $request->validate(['code' => ['required', 'string']]);
        $user = MonitorUser::query()->findOrFail($userId);

        if ($this->isValidTotpCode($user, $validated['code']) || $this->isValidRecoveryCode($user, $validated['code'])) {
            $request->session()->forget('monitor_2fa_challenge_user_id');
            Auth::guard(MonitorUser::guardName())->login($user);
            $request->session()->regenerate();

            return redirect()->route('monitor.dashboard');
        }

        throw ValidationException::withMessages([
            'code' => 'That code did not match. Please try again.',
        ]);
    }

    protected function isValidTotpCode(MonitorUser $user, string $code): bool
    {
        return (new Google2FA())->verifyKey($user->totp_secret, $code);
    }

    protected function isValidRecoveryCode(MonitorUser $user, string $code): bool
    {
        $recoveryCodes = $user->totp_recovery_codes ?? [];

        foreach ($recoveryCodes as $index => $hashedCode) {
            if (Hash::check($code, $hashedCode)) {
                unset($recoveryCodes[$index]);
                $user->update(['totp_recovery_codes' => array_values($recoveryCodes)]);

                return true;
            }
        }

        return false;
    }
}
```

- [ ] **Step 5: Add the routes**

In `routes/web.php`, add the import:

```php
use LaravelMonitor\Http\Controllers\Auth\TwoFactorChallengeController;
```

Add two routes right after the `monitor.logout` route (still outside `EnsureMonitorAuthenticated`, same as login/setup):

```php
        Route::get('/two-factor-challenge', [TwoFactorChallengeController::class, 'show'])->name('monitor.two-factor.challenge');
        Route::post('/two-factor-challenge', [TwoFactorChallengeController::class, 'store'])->name('monitor.two-factor.challenge.store');
```

- [ ] **Step 6: Create the challenge view**

Create `resources/views/auth/two-factor-challenge.blade.php`, mirroring `resources/views/auth/login.blade.php`'s layout:

```blade
{{-- Post-password TOTP challenge. See Http\Controllers\Auth\TwoFactorChallengeController. --}}
<x-monitor::layout title="Two-factor authentication">
    <div class="flex min-h-screen items-center justify-center bg-neutral-50 px-4 dark:bg-neutral-950">
        <div class="w-full max-w-sm">
            <div class="rounded-lg border border-neutral-200 bg-white p-6 shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                <h1 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">Two-factor authentication</h1>
                <p class="mt-1 text-sm text-neutral-500 dark:text-neutral-400">Enter the 6-digit code from your authenticator app, or a recovery code.</p>

                @if ($errors->any())
                    <div class="mt-4 rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-600 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-400">
                        {{ $errors->first() }}
                    </div>
                @endif

                <form method="POST" action="{{ route('monitor.two-factor.challenge.store') }}" class="mt-4 space-y-4">
                    @csrf
                    <div>
                        <label for="code" class="block font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400">Code</label>
                        <input type="text" name="code" id="code" required autofocus
                               class="mt-1 w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-900 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100">
                    </div>
                    <button type="submit" class="w-full rounded-md bg-blue-600 py-2 text-sm font-medium text-white hover:bg-blue-500">Verify</button>
                </form>
            </div>
        </div>
    </div>
</x-monitor::layout>
```

- [ ] **Step 7: Run the tests to verify they pass**

Run: `/opt/homebrew/bin/php vendor/bin/phpunit --filter=TwoFactorTest`
Expected: PASS. Also re-run the existing login tests to confirm the `store()` refactor didn't regress the non-TOTP path:

Run: `/opt/homebrew/bin/php vendor/bin/phpunit --filter=MonitorTest`
Expected: PASS, unchanged.

- [ ] **Step 8: Syntax-check the view and run the full suite**

Run: `/opt/homebrew/bin/php -l resources/views/auth/two-factor-challenge.blade.php`
Expected: `No syntax errors detected`.

Run: `/opt/homebrew/bin/php vendor/bin/phpunit`
Expected: PASS, 138 tests (133 + 5 new).

- [ ] **Step 9: Commit**

```bash
git add src/Http/Controllers/Auth/LoginController.php src/Http/Controllers/Auth/TwoFactorChallengeController.php resources/views/auth/two-factor-challenge.blade.php routes/web.php tests/TwoFactorTest.php
git commit -m "feat: add the TOTP login challenge"
```

---

### Task 4: TOTP disable — self-service and admin override

**Files:**
- Modify: `src/Livewire/Team.php`
- Modify: `resources/views/livewire/team.blade.php`
- Modify (append to): `tests/TwoFactorTest.php`

**Interfaces:**
- Consumes: `MonitorUser::hasTotpEnabled()` (Task 1), `Team::actor()` (existing protected method).
- Produces: `Team::disableTotp(string $currentPassword): void` (self-service, password-gated), `Team::disableMemberTotp(int $memberId): void` (owner-only override) — no later task depends on these.

- [ ] **Step 1: Write the failing tests**

Append to `tests/TwoFactorTest.php`:

```php
    public function test_disabling_totp_requires_the_correct_current_password(): void
    {
        $owner = $this->actingAsOwner();
        $owner->update([
            'totp_secret' => (new Google2FA())->generateSecretKey(),
            'totp_enabled_at' => now(),
            'totp_recovery_codes' => [],
        ]);

        Livewire::test(Team::class)->call('disableTotp', 'wrong-password');

        $this->assertTrue($owner->refresh()->hasTotpEnabled());
    }

    public function test_disabling_totp_with_the_correct_password_clears_it(): void
    {
        $owner = $this->actingAsOwner();
        $owner->update([
            'totp_secret' => (new Google2FA())->generateSecretKey(),
            'totp_enabled_at' => now(),
            'totp_recovery_codes' => ['a-hash'],
        ]);

        Livewire::test(Team::class)->call('disableTotp', 'password');

        $owner->refresh();
        $this->assertFalse($owner->hasTotpEnabled());
        $this->assertNull($owner->totp_secret);
        $this->assertNull($owner->totp_recovery_codes);
    }

    public function test_owner_can_disable_another_members_totp(): void
    {
        $this->actingAsOwner();
        $member = MonitorUser::create([
            'name' => 'Locked Out', 'email' => 'locked-out@example.com',
            'password' => Hash::make('password'), 'role' => 'admin',
            'totp_secret' => (new Google2FA())->generateSecretKey(),
            'totp_enabled_at' => now(), 'totp_recovery_codes' => [],
        ]);

        Livewire::test(Team::class)->call('disableMemberTotp', $member->id);

        $this->assertFalse($member->refresh()->hasTotpEnabled());
    }

    public function test_admin_cannot_disable_another_members_totp(): void
    {
        $admin = MonitorUser::create([
            'name' => 'Admin', 'email' => 'admin-2fa-test@example.com',
            'password' => Hash::make('password'), 'role' => 'admin',
        ]);
        $this->actingAs($admin, MonitorUser::guardName());

        $member = MonitorUser::create([
            'name' => 'Locked Out 2', 'email' => 'locked-out-2@example.com',
            'password' => Hash::make('password'), 'role' => 'viewer',
            'totp_secret' => (new Google2FA())->generateSecretKey(),
            'totp_enabled_at' => now(), 'totp_recovery_codes' => [],
        ]);

        Livewire::test(Team::class)->call('disableMemberTotp', $member->id)
            ->assertForbidden();

        $this->assertTrue($member->refresh()->hasTotpEnabled());
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `/opt/homebrew/bin/php vendor/bin/phpunit --filter=TwoFactorTest`
Expected: FAIL — `disableTotp`/`disableMemberTotp` don't exist on `Team` yet.

- [ ] **Step 3: Add the two methods to `Team`**

Add to `src/Livewire/Team.php` (needs `use Illuminate\Support\Facades\Hash;` already added in Task 2):

```php
    public function disableTotp(string $currentPassword): void
    {
        $actor = $this->actor();

        if (! Hash::check($currentPassword, $actor->password)) {
            $this->addError('totp', 'Your current password was incorrect.');

            return;
        }

        $actor->update(['totp_secret' => null, 'totp_enabled_at' => null, 'totp_recovery_codes' => null]);
    }

    public function disableMemberTotp(int $memberId): void
    {
        $actor = $this->actor();

        if (! $actor->isOwner()) {
            abort(403);
        }

        MonitorUser::query()->find($memberId)?->update([
            'totp_secret' => null,
            'totp_enabled_at' => null,
            'totp_recovery_codes' => null,
        ]);
    }
```

- [ ] **Step 4: Add the disable UI**

In `resources/views/livewire/team.blade.php`, replace the `@elseif ($actor->hasTotpEnabled())` branch added in Task 2 with:

```blade
            @elseif ($actor->hasTotpEnabled())
                <p class="mt-2 text-sm text-neutral-500 dark:text-neutral-400">Enabled for your account.</p>
                <form wire:submit="disableTotp($refs.currentPassword.value)" class="mt-3 flex flex-wrap items-end gap-2" x-data>
                    <div class="min-w-0 flex-1">
                        <label class="block font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400">Current password</label>
                        <input type="password" x-ref="currentPassword" required
                               class="mt-1 w-full rounded-md border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 px-2 py-1.5 text-sm text-neutral-900 dark:text-neutral-100 focus:outline-none">
                    </div>
                    <button type="submit" class="h-8 rounded-md border border-rose-200 dark:border-rose-500/30 bg-white dark:bg-neutral-900 px-2 py-1 font-mono text-[10px] uppercase tracking-tight text-rose-600 dark:text-rose-400 shadow-sm hover:bg-rose-50 dark:hover:bg-rose-500/10">Disable</button>
                </form>
                @error('totp')
                    <p class="mt-2 text-sm text-rose-600 dark:text-rose-400">{{ $message }}</p>
                @enderror
```

In the members list `@foreach ($members as $member)` loop (inside the `@if ($actor->isOwner() && $member->id !== $actor->id)` branch, right after the existing "Make owner" button and before "Remove"), add:

```blade
                            @if ($member->hasTotpEnabled())
                                <button type="button" wire:click="disableMemberTotp({{ $member->id }})" wire:confirm="Disable two-factor authentication for {{ $member->name }}?"
                                        class="shrink-0 rounded-md border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 px-2 py-1 font-mono text-[10px] uppercase tracking-tight text-neutral-500 dark:text-neutral-400 shadow-sm hover:bg-neutral-50 dark:hover:bg-neutral-800/50">Disable 2FA</button>
                            @endif
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `/opt/homebrew/bin/php vendor/bin/phpunit --filter=TwoFactorTest`
Expected: PASS.

- [ ] **Step 6: Syntax-check and run the full suite**

Run: `/opt/homebrew/bin/php -l resources/views/livewire/team.blade.php`
Expected: `No syntax errors detected`.

Run: `/opt/homebrew/bin/php vendor/bin/phpunit`
Expected: PASS, 142 tests (138 + 4 new).

- [ ] **Step 7: Commit**

```bash
git add src/Livewire/Team.php resources/views/livewire/team.blade.php tests/TwoFactorTest.php
git commit -m "feat: add self-service and admin-override TOTP disable"
```

---

### Task 5: Passkey registration

**Files:**
- Create: `src/Support/WebauthnCredentialRepository.php`
- Create: `src/Http/Controllers/Auth/WebauthnController.php`
- Modify: `src/Livewire/Team.php`
- Modify: `resources/views/livewire/team.blade.php`
- Modify: `routes/web.php`
- Test: `tests/PasskeyTest.php`

**Interfaces:**
- Consumes: `MonitorWebauthnCredential` model, `OptionalAuthMethod::passkeysAvailable()` (Task 1).
- Produces: `WebauthnCredentialRepository` implementing `Webauthn\PublicKeyCredentialSourceRepository` (Task 6's authentication flow uses the same repository to look up a credential by ID); `POST /monitor/webauthn/register/options` and `POST /monitor/webauthn/register` routes; `Team::removePasskey(int $credentialId): void`.

**Before starting:** `web-auth/webauthn-lib`'s exact API can shift between major versions. Once `composer install` has pulled it in (Task 1), read `vendor/web-auth/webauthn-lib/src/Server.php` and `vendor/web-auth/webauthn-lib/src/PublicKeyCredentialSourceRepository.php` directly to confirm the method names/signatures below still match the installed version before writing the controller — adjust names if they've drifted, the ceremony's shape (options → client ceremony → verify → persist) will not have changed.

- [ ] **Step 1: Write the failing tests**

Create `tests/PasskeyTest.php`:

```php
<?php

namespace LaravelMonitor\Tests;

use Illuminate\Support\Facades\Hash;
use LaravelMonitor\Livewire\Team;
use LaravelMonitor\Models\MonitorUser;
use LaravelMonitor\Models\MonitorWebauthnCredential;
use Livewire\Livewire;

class PasskeyTest extends TestCase
{
    public function test_registration_options_are_returned_for_an_authenticated_user(): void
    {
        $this->postJson('/monitor/webauthn/register/options')
            ->assertOk()
            ->assertJsonStructure(['challenge', 'rp', 'user']);
    }

    public function test_a_valid_registration_response_persists_a_credential_for_the_current_user(): void
    {
        $owner = MonitorUser::query()->where('email', 'owner@example.com')->firstOrFail();

        $optionsResponse = $this->postJson('/monitor/webauthn/register/options')->json();

        [$attestationResponse, $credentialId] = $this->fakeAttestationResponseFor($optionsResponse);

        $this->postJson('/monitor/webauthn/register', [
            'label' => 'Test device',
            'response' => $attestationResponse,
        ])->assertOk();

        $this->assertDatabaseHas((new MonitorWebauthnCredential())->getTable(), [
            'user_id' => $owner->id,
            'label' => 'Test device',
        ]);
    }

    public function test_removing_a_passkey_only_removes_the_owning_users_row(): void
    {
        $owner = MonitorUser::query()->where('email', 'owner@example.com')->firstOrFail();
        $credential = MonitorWebauthnCredential::create([
            'user_id' => $owner->id, 'credential_id' => 'cred-1',
            'public_key' => 'key', 'label' => 'To remove',
        ]);

        Livewire::test(Team::class)->call('removePasskey', $credential->id);

        $this->assertDatabaseMissing((new MonitorWebauthnCredential())->getTable(), ['id' => $credential->id]);
    }

    public function test_removing_someone_elses_passkey_does_nothing(): void
    {
        $other = MonitorUser::create([
            'name' => 'Other', 'email' => 'other-passkey-owner@example.com',
            'password' => Hash::make('password'), 'role' => 'viewer',
        ]);
        $credential = MonitorWebauthnCredential::create([
            'user_id' => $other->id, 'credential_id' => 'cred-2',
            'public_key' => 'key', 'label' => 'Not mine',
        ]);

        Livewire::test(Team::class)->call('removePasskey', $credential->id);

        $this->assertDatabaseHas((new MonitorWebauthnCredential())->getTable(), ['id' => $credential->id]);
    }

    /**
     * A registration ceremony's attestation response is a signed, CBOR-encoded
     * byte structure that cannot be hand-authored — find a working
     * registration fixture (options + matching client response pair) in
     * vendor/web-auth/webauthn-lib/tests/ (its own Functional/Unit test
     * suite ships exactly this kind of fixture for its own assertions) and
     * adapt it here: swap in the challenge from $optionsResponse and this
     * test's own RP ID/origin so it verifies against options this test
     * actually generated. Return [the decoded JSON response body the
     * browser would post, the credential's base64url ID].
     *
     * Alternative if no usable fixture is found: extract the
     * `$this->server()->loadAndCheckAttestationResponse(...)` call in
     * WebauthnController::register() behind a small injectable class this
     * package owns (bind it in MonitorServiceProvider, inject it into the
     * controller), then swap that binding for a fake in this test that
     * returns a hand-constructed PublicKeyCredentialSource directly,
     * skipping real signature verification entirely. Prefer the fixture
     * approach first — it also exercises the real verification path.
     */
    protected function fakeAttestationResponseFor(array $optionsResponse): array
    {
        // Implement using a vendor/web-auth/webauthn-lib test fixture — see docblock above.
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `/opt/homebrew/bin/php vendor/bin/phpunit --filter=PasskeyTest`
Expected: FAIL — none of the routes/methods exist yet.

- [ ] **Step 3: Create the credential repository**

Create `src/Support/WebauthnCredentialRepository.php`:

```php
<?php

namespace LaravelMonitor\Support;

use LaravelMonitor\Models\MonitorWebauthnCredential;
use Webauthn\PublicKeyCredentialSource;
use Webauthn\PublicKeyCredentialSourceRepository;
use Webauthn\PublicKeyCredentialUserEntity;

class WebauthnCredentialRepository implements PublicKeyCredentialSourceRepository
{
    public function findOneByCredentialId(string $publicKeyCredentialId): ?PublicKeyCredentialSource
    {
        $credential = MonitorWebauthnCredential::query()
            ->where('credential_id', base64_encode($publicKeyCredentialId))
            ->first();

        return $credential === null ? null : $this->toSource($credential);
    }

    public function findAllForUserEntity(PublicKeyCredentialUserEntity $userEntity): array
    {
        return MonitorWebauthnCredential::query()
            ->where('user_id', $userEntity->id)
            ->get()
            ->map(fn (MonitorWebauthnCredential $credential) => $this->toSource($credential))
            ->all();
    }

    public function saveCredentialSource(PublicKeyCredentialSource $publicKeyCredentialSource): void
    {
        MonitorWebauthnCredential::query()->updateOrCreate(
            ['credential_id' => base64_encode($publicKeyCredentialSource->publicKeyCredentialId)],
            [
                'user_id' => $publicKeyCredentialSource->userHandle,
                'public_key' => base64_encode(serialize($publicKeyCredentialSource)),
                'sign_count' => $publicKeyCredentialSource->counter,
                'label' => request()->input('label', 'Passkey'),
            ],
        );
    }

    protected function toSource(MonitorWebauthnCredential $credential): PublicKeyCredentialSource
    {
        return unserialize(base64_decode($credential->public_key));
    }
}
```

Note: storing the whole serialized `PublicKeyCredentialSource` in `public_key` (rather than just the raw public key bytes) is deliberate — the object also carries the attestation type, transports, and AAGUID that the library's own assertion verification expects back unchanged. Confirm `PublicKeyCredentialSource` is `Serializable`/safe to `serialize()` against the installed version (per this task's "Before starting" note) — if the installed version instead documents its own `WebauthnSerializerFactory` for this, use that to encode/decode instead of raw PHP `serialize()`.

- [ ] **Step 4: Create `WebauthnController`**

Create `src/Http/Controllers/Auth/WebauthnController.php`:

```php
<?php

namespace LaravelMonitor\Http\Controllers\Auth;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LaravelMonitor\Models\MonitorUser;
use LaravelMonitor\Support\OptionalAuthMethod;
use LaravelMonitor\Support\WebauthnCredentialRepository;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialUserEntity;
use Webauthn\Server;

class WebauthnController
{
    public function registerOptions(Request $request): JsonResponse
    {
        abort_unless(OptionalAuthMethod::passkeysAvailable(), 404);

        $actor = $request->user(MonitorUser::guardName());
        $options = $this->server()->generatePublicKeyCredentialCreationOptions(
            new PublicKeyCredentialUserEntity($actor->email, (string) $actor->id, $actor->name),
        );

        $request->session()->put('monitor_webauthn_creation_options', serialize($options));

        return response()->json($options);
    }

    public function register(Request $request): JsonResponse
    {
        abort_unless(OptionalAuthMethod::passkeysAvailable(), 404);

        $validated = $request->validate(['label' => ['required', 'string', 'max:255'], 'response' => ['required']]);
        $options = unserialize($request->session()->get('monitor_webauthn_creation_options'));

        $this->server()->loadAndCheckAttestationResponse(
            json_encode($validated['response']),
            $options,
            $request,
        );

        $request->session()->forget('monitor_webauthn_creation_options');

        return response()->json(['status' => 'ok']);
    }

    protected function server(): Server
    {
        return new Server(
            new PublicKeyCredentialRpEntity(config('app.name', 'Laravel Monitor')),
            new WebauthnCredentialRepository(),
        );
    }
}
```

- [ ] **Step 5: Add the routes**

In `routes/web.php`, add the import `use LaravelMonitor\Http\Controllers\Auth\WebauthnController;`, and inside the `EnsureMonitorAuthenticated` group (registration requires an existing session — it's adding a passkey to your own account):

```php
            Route::post('/webauthn/register/options', [WebauthnController::class, 'registerOptions'])->name('monitor.webauthn.register.options');
            Route::post('/webauthn/register', [WebauthnController::class, 'register'])->name('monitor.webauthn.register.store');
```

- [ ] **Step 6: Add `Team::removePasskey()` and the Passkeys card**

Add to `src/Livewire/Team.php`:

```php
    public function removePasskey(int $credentialId): void
    {
        $actor = $this->actor();

        \LaravelMonitor\Models\MonitorWebauthnCredential::query()
            ->where('id', $credentialId)
            ->where('user_id', $actor->id)
            ->delete();
    }
```

Add `'passkeys' => \LaravelMonitor\Models\MonitorWebauthnCredential::query()->where('user_id', $actor->id)->get(),` to the array returned by `Team::data()`.

In `resources/views/livewire/team.blade.php`, after the Two-factor authentication card, add:

```blade
        <x-monitor::card class="mt-4 p-4">
            <p class="font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400">Passkeys</p>
            @if (! \LaravelMonitor\Support\OptionalAuthMethod::passkeysAvailable())
                <p class="mt-2 text-sm text-neutral-400 dark:text-neutral-500">Install <code class="font-mono text-xs">web-auth/webauthn-lib</code> to enable this.</p>
            @else
                <div class="mt-3 divide-y divide-neutral-100 dark:divide-neutral-800">
                    @foreach ($passkeys as $passkey)
                        <div class="flex items-center gap-3 py-2">
                            <span class="min-w-0 flex-1 truncate text-sm text-neutral-700 dark:text-neutral-200">{{ $passkey->label }}</span>
                            <button type="button" wire:click="removePasskey({{ $passkey->id }})" wire:confirm="Remove this passkey?"
                                    class="shrink-0 rounded-md border border-rose-200 dark:border-rose-500/30 bg-white dark:bg-neutral-900 px-2 py-1 font-mono text-[10px] uppercase tracking-tight text-rose-600 dark:text-rose-400 shadow-sm hover:bg-rose-50 dark:hover:bg-rose-500/10">Remove</button>
                        </div>
                    @endforeach
                </div>
                <button type="button" id="add-passkey-button" class="mt-3 h-8 rounded-md bg-blue-600 px-3 text-sm font-medium text-white hover:bg-blue-500">Add a passkey</button>
                <script>
                    document.getElementById('add-passkey-button')?.addEventListener('click', async () => {
                        const options = await (await fetch('{{ route('monitor.webauthn.register.options') }}', {
                            method: 'POST',
                            headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                        })).json();

                        const credential = await navigator.credentials.create({ publicKey: options });
                        const label = prompt('Name this passkey', 'My device') || 'Passkey';

                        await fetch('{{ route('monitor.webauthn.register.store') }}', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                            body: JSON.stringify({ label, response: credential }),
                        });

                        window.location.reload();
                    });
                </script>
            @endif
        </x-monitor::card>
```

- [ ] **Step 7: Run the tests and fix the fixture**

Run: `/opt/homebrew/bin/php vendor/bin/phpunit --filter=PasskeyTest`
Expected: `test_registration_options_are_returned_for_an_authenticated_user`, `test_removing_a_passkey_only_removes_the_owning_users_row`, and `test_removing_someone_elses_passkey_does_nothing` PASS immediately. `test_a_valid_registration_response_persists_a_credential_for_the_current_user` depends on `fakeAttestationResponseFor()` — implement it now using a fixture mined from `vendor/web-auth/webauthn-lib/tests/` per that method's docblock, then re-run until it passes.

- [ ] **Step 8: Syntax-check and run the full suite**

Run: `/opt/homebrew/bin/php -l resources/views/livewire/team.blade.php`
Expected: `No syntax errors detected`.

Run: `/opt/homebrew/bin/php vendor/bin/phpunit`
Expected: PASS, 146 tests (142 + 4 new).

- [ ] **Step 9: Commit**

```bash
git add src/Support/WebauthnCredentialRepository.php src/Http/Controllers/Auth/WebauthnController.php src/Livewire/Team.php resources/views/livewire/team.blade.php routes/web.php tests/PasskeyTest.php
git commit -m "feat: add passkey registration"
```

---

### Task 6: Passkey login

**Files:**
- Modify: `src/Http/Controllers/Auth/WebauthnController.php`
- Modify: `resources/views/auth/login.blade.php`
- Modify: `routes/web.php`
- Modify (append to): `tests/PasskeyTest.php`

**Interfaces:**
- Consumes: `WebauthnCredentialRepository` (Task 5) — same repository backs both ceremonies.
- Produces: nothing further consumed by later tasks — this is the last passkey-specific task.

- [ ] **Step 1: Write the failing tests**

Append to `tests/PasskeyTest.php`:

```php
    public function test_authentication_options_do_not_require_a_prior_login(): void
    {
        $this->withoutMonitorAuth();

        $this->postJson('/monitor/webauthn/authenticate/options')
            ->assertOk()
            ->assertJsonStructure(['challenge']);
    }

    public function test_a_valid_authentication_response_logs_in_the_owning_user(): void
    {
        $this->withoutMonitorAuth();
        $owner = MonitorUser::query()->where('email', 'owner@example.com')->firstOrFail();

        $optionsResponse = $this->postJson('/monitor/webauthn/authenticate/options')->json();
        [$assertionResponse] = $this->fakeAssertionResponseFor($optionsResponse, $owner);

        $this->postJson('/monitor/webauthn/authenticate', ['response' => $assertionResponse])
            ->assertRedirect('/monitor');

        $this->assertSame($owner->id, Auth::guard(MonitorUser::guardName())->id());
    }

    /**
     * Same constraint as fakeAttestationResponseFor() above — an assertion
     * response is a signed byte structure. Register a real credential for
     * $user first (reuse fakeAttestationResponseFor()'s fixture via the
     * register routes), then produce the matching assertion response from
     * vendor/web-auth/webauthn-lib/tests/ fixtures for that same credential.
     * Same container-binding fallback as fakeAttestationResponseFor() also
     * applies here if no usable fixture pair is found.
     */
    protected function fakeAssertionResponseFor(array $optionsResponse, MonitorUser $user): array
    {
        // Implement using a vendor/web-auth/webauthn-lib test fixture — see docblock above.
    }
```

Add `use Illuminate\Support\Facades\Auth;` to the top of `tests/PasskeyTest.php` if not already present from Task 5.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `/opt/homebrew/bin/php vendor/bin/phpunit --filter=PasskeyTest`
Expected: FAIL — the two new routes don't exist yet.

- [ ] **Step 3: Add the authentication methods to `WebauthnController`**

Add to `src/Http/Controllers/Auth/WebauthnController.php`:

```php
    public function authenticateOptions(Request $request): JsonResponse
    {
        abort_unless(OptionalAuthMethod::passkeysAvailable(), 404);

        $options = $this->server()->generatePublicKeyCredentialRequestOptions(
            \Webauthn\PublicKeyCredentialRequestOptions::USER_VERIFICATION_REQUIREMENT_PREFERRED,
        );

        $request->session()->put('monitor_webauthn_request_options', serialize($options));

        return response()->json($options);
    }

    public function authenticate(Request $request): \Illuminate\Http\RedirectResponse
    {
        abort_unless(OptionalAuthMethod::passkeysAvailable(), 404);

        $validated = $request->validate(['response' => ['required']]);
        $options = unserialize($request->session()->get('monitor_webauthn_request_options'));

        $source = $this->server()->loadAndCheckAssertionResponse(
            json_encode($validated['response']),
            $options,
            null,
            $request,
        );

        $request->session()->forget('monitor_webauthn_request_options');

        $user = MonitorUser::query()->findOrFail($source->userHandle);
        \Illuminate\Support\Facades\Auth::guard(MonitorUser::guardName())->login($user);
        $request->session()->regenerate();

        return redirect()->route('monitor.dashboard');
    }
```

- [ ] **Step 4: Add the public (unauthenticated) routes**

In `routes/web.php`, next to the other unauthenticated auth routes (e.g. after `monitor.two-factor.challenge.store`):

```php
        Route::post('/webauthn/authenticate/options', [WebauthnController::class, 'authenticateOptions'])->name('monitor.webauthn.authenticate.options');
        Route::post('/webauthn/authenticate', [WebauthnController::class, 'authenticate'])->name('monitor.webauthn.authenticate.store');
```

- [ ] **Step 5: Add the login-page button**

In `resources/views/auth/login.blade.php`, after the closing `</form>` and before the "Forgot your password?" paragraph, add:

```blade
                <button type="button" id="passkey-login-button" @if (! \LaravelMonitor\Support\OptionalAuthMethod::passkeysAvailable()) disabled @endif
                        class="mt-3 w-full rounded-md border border-neutral-200 py-2 text-sm font-medium text-neutral-700 hover:bg-neutral-50 disabled:cursor-not-allowed disabled:opacity-50 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800/50">
                    Sign in with a passkey
                </button>
                @unless (\LaravelMonitor\Support\OptionalAuthMethod::passkeysAvailable())
                    <p class="mt-1 text-center text-xs text-neutral-400 dark:text-neutral-500">Install <code class="font-mono">web-auth/webauthn-lib</code> to enable this.</p>
                @endunless
                <script>
                    document.getElementById('passkey-login-button')?.addEventListener('click', async () => {
                        const options = await (await fetch('{{ route('monitor.webauthn.authenticate.options') }}', {
                            method: 'POST',
                            headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                        })).json();

                        const credential = await navigator.credentials.get({ publicKey: options });

                        const response = await fetch('{{ route('monitor.webauthn.authenticate.store') }}', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                            body: JSON.stringify({ response: credential }),
                        });

                        window.location.href = response.url;
                    });
                </script>
```

- [ ] **Step 6: Run the tests and fix the fixture**

Run: `/opt/homebrew/bin/php vendor/bin/phpunit --filter=PasskeyTest`
Expected: `test_authentication_options_do_not_require_a_prior_login` PASSes immediately. Implement `fakeAssertionResponseFor()` per its docblock, then re-run until `test_a_valid_authentication_response_logs_in_the_owning_user` PASSes too.

- [ ] **Step 7: Syntax-check and run the full suite**

Run: `/opt/homebrew/bin/php -l resources/views/auth/login.blade.php`
Expected: `No syntax errors detected`.

Run: `/opt/homebrew/bin/php vendor/bin/phpunit`
Expected: PASS, 148 tests (146 + 2 new).

- [ ] **Step 8: Commit**

```bash
git add src/Http/Controllers/Auth/WebauthnController.php resources/views/auth/login.blade.php routes/web.php tests/PasskeyTest.php
git commit -m "feat: add passkey login"
```

---

### Task 7: OAuth login — Google

**Files:**
- Create: `src/Http/Controllers/Auth/OAuthController.php`
- Modify: `src/MonitorServiceProvider.php`
- Modify: `resources/views/auth/login.blade.php`
- Modify: `routes/web.php`
- Test: `tests/OAuthLoginTest.php`

**Interfaces:**
- Consumes: `MonitorOauthAccount` model (Task 1), `OptionalAuthMethod::oauthAvailable('google')` (Task 1).
- Produces: `OAuthController::redirect(string $provider)`/`::callback(string $provider)`, routes `monitor.oauth.redirect`/`monitor.oauth.callback`, `MonitorServiceProvider::registerOAuth()` (bridges `monitor.auth.oauth.*` config into Socialite's own `services.*` config) — Task 8 (Apple) reuses the same controller/routes, only adding a provider registration + config.

- [ ] **Step 1: Write the failing tests**

Create `tests/OAuthLoginTest.php`:

```php
<?php

namespace LaravelMonitor\Tests;

use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use LaravelMonitor\Models\MonitorOauthAccount;
use LaravelMonitor\Models\MonitorUser;

class OAuthLoginTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['monitor.auth.oauth.google.client_id' => 'test-client-id']);
    }

    public function test_a_callback_with_an_email_matching_an_existing_user_logs_them_in(): void
    {
        $this->withoutMonitorAuth();

        Socialite::fake('google', (new SocialiteUser())->map([
            'id' => 'google-123',
            'name' => 'Owner',
            'email' => 'owner@example.com',
        ]));

        $this->get('/monitor/oauth/google/callback')->assertRedirect('/monitor');

        $owner = MonitorUser::query()->where('email', 'owner@example.com')->firstOrFail();
        $this->assertSame($owner->id, Auth::guard(MonitorUser::guardName())->id());
        $this->assertDatabaseHas((new MonitorOauthAccount())->getTable(), [
            'user_id' => $owner->id, 'provider' => 'google', 'provider_user_id' => 'google-123',
        ]);
    }

    public function test_a_callback_with_an_unmatched_email_shows_an_error_and_creates_nothing(): void
    {
        $this->withoutMonitorAuth();

        Socialite::fake('google', (new SocialiteUser())->map([
            'id' => 'google-456',
            'name' => 'Nobody',
            'email' => 'not-a-member@example.com',
        ]));

        $this->get('/monitor/oauth/google/callback')->assertSessionHasErrors('email');

        $this->assertFalse(Auth::guard(MonitorUser::guardName())->check());
        $this->assertDatabaseMissing((new MonitorUser())->getTable(), ['email' => 'not-a-member@example.com']);
    }

    public function test_a_second_login_through_the_same_provider_reuses_the_linked_account(): void
    {
        $this->withoutMonitorAuth();
        $owner = MonitorUser::query()->where('email', 'owner@example.com')->firstOrFail();

        Socialite::fake('google', (new SocialiteUser())->map([
            'id' => 'google-789', 'name' => 'Owner', 'email' => 'owner@example.com',
        ]));
        $this->get('/monitor/oauth/google/callback');
        $this->withoutMonitorAuth();
        $this->get('/monitor/oauth/google/callback');

        $this->assertSame(1, MonitorOauthAccount::query()->where('provider', 'google')->where('provider_user_id', 'google-789')->count());
    }

    public function test_google_login_button_is_disabled_without_a_configured_client_id(): void
    {
        config(['monitor.auth.oauth.google.client_id' => null]);
        $this->withoutMonitorAuth();

        $this->get('/monitor/login')->assertSeeText('Install laravel/socialite');
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `/opt/homebrew/bin/php vendor/bin/phpunit --filter=OAuthLoginTest`
Expected: FAIL — `/monitor/oauth/google/callback` doesn't exist yet.

- [ ] **Step 3: Create `OAuthController`**

Create `src/Http/Controllers/Auth/OAuthController.php`:

```php
<?php

namespace LaravelMonitor\Http\Controllers\Auth;

use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Laravel\Socialite\Facades\Socialite;
use LaravelMonitor\Models\MonitorOauthAccount;
use LaravelMonitor\Models\MonitorUser;

class OAuthController
{
    public function redirect(string $provider): RedirectResponse
    {
        return Socialite::driver($provider)->redirect();
    }

    public function callback(string $provider): RedirectResponse
    {
        $socialiteUser = Socialite::driver($provider)->user();

        $user = MonitorUser::query()->where('email', $socialiteUser->getEmail())->first();

        if ($user === null) {
            throw ValidationException::withMessages([
                'email' => 'No dashboard account uses this email — ask an owner/admin to invite you.',
            ]);
        }

        MonitorOauthAccount::query()->updateOrCreate(
            ['provider' => $provider, 'provider_user_id' => $socialiteUser->getId()],
            ['user_id' => $user->id],
        );

        \Illuminate\Support\Facades\Auth::guard(MonitorUser::guardName())->login($user);
        request()->session()->regenerate();

        return redirect()->route('monitor.dashboard');
    }
}
```

- [ ] **Step 4: Bridge `monitor.auth.oauth.*` config into Socialite's `services.*` config**

In `src/MonitorServiceProvider.php`, call a new `$this->registerOAuth();` at the end of `register()`'s existing call list (next to `$this->registerAuth();`), and add the method (mirroring `registerAuth()`'s "don't clobber a host override" pattern):

```php
    /**
     * Socialite reads driver config from `services.<provider>`, which this
     * package has no business publishing into the host app's own
     * config/services.php — mirror registerAuth()'s approach instead and
     * merge it at runtime from this package's own `monitor.auth.oauth.*`,
     * skipping any provider the host app already configured itself.
     */
    protected function registerOAuth(): void
    {
        foreach (['google', 'apple'] as $provider) {
            if (! $this->app['config']->has("services.{$provider}")) {
                $this->app['config']->set("services.{$provider}", $this->app['config']->get("monitor.auth.oauth.{$provider}", []));
            }
        }
    }
```

- [ ] **Step 5: Add the routes**

In `routes/web.php`, add the import `use LaravelMonitor\Http\Controllers\Auth\OAuthController;`, and next to the other unauthenticated auth routes:

```php
        Route::get('/oauth/{provider}/redirect', [OAuthController::class, 'redirect'])->name('monitor.oauth.redirect');
        Route::get('/oauth/{provider}/callback', [OAuthController::class, 'callback'])->name('monitor.oauth.callback');
```

- [ ] **Step 6: Add the Google login button**

In `resources/views/auth/login.blade.php`, after the passkey button block added in Task 6:

```blade
                <a href="{{ \LaravelMonitor\Support\OptionalAuthMethod::oauthAvailable('google') ? route('monitor.oauth.redirect', 'google') : '#' }}"
                   class="mt-3 flex w-full items-center justify-center rounded-md border border-neutral-200 py-2 text-sm font-medium text-neutral-700 hover:bg-neutral-50 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800/50 {{ \LaravelMonitor\Support\OptionalAuthMethod::oauthAvailable('google') ? '' : 'cursor-not-allowed opacity-50' }}">
                    Continue with Google
                </a>
                @unless (\LaravelMonitor\Support\OptionalAuthMethod::oauthAvailable('google'))
                    <p class="mt-1 text-center text-xs text-neutral-400 dark:text-neutral-500">Install <code class="font-mono">laravel/socialite</code> and configure <code class="font-mono">MONITOR_GOOGLE_CLIENT_ID</code> to enable this.</p>
                @endunless
```

- [ ] **Step 7: Run the tests to verify they pass**

Run: `/opt/homebrew/bin/php vendor/bin/phpunit --filter=OAuthLoginTest`
Expected: PASS.

- [ ] **Step 8: Syntax-check and run the full suite**

Run: `/opt/homebrew/bin/php -l resources/views/auth/login.blade.php`
Expected: `No syntax errors detected`.

Run: `/opt/homebrew/bin/php vendor/bin/phpunit`
Expected: PASS, 152 tests (148 + 4 new).

- [ ] **Step 9: Commit**

```bash
git add src/Http/Controllers/Auth/OAuthController.php src/MonitorServiceProvider.php resources/views/auth/login.blade.php routes/web.php tests/OAuthLoginTest.php
git commit -m "feat: add OAuth login for Google"
```

---

### Task 8: OAuth login — Apple

**Files:**
- Modify: `src/MonitorServiceProvider.php`
- Modify: `resources/views/auth/login.blade.php`
- Modify (append to): `tests/OAuthLoginTest.php`

**Interfaces:**
- Consumes: `OAuthController` and its routes (Task 7) — both providers share the exact same controller/routes, this task only registers the `apple` driver and adds its button.
- Produces: nothing further — this is the final task in the plan.

- [ ] **Step 1: Write the failing tests**

Append to `tests/OAuthLoginTest.php`:

```php
    public function test_apple_callback_with_a_matching_email_logs_the_user_in(): void
    {
        config(['monitor.auth.oauth.apple.client_id' => 'test-apple-client-id']);
        $this->withoutMonitorAuth();

        Socialite::fake('apple', (new SocialiteUser())->map([
            'id' => 'apple-123', 'name' => 'Owner', 'email' => 'owner@example.com',
        ]));

        $this->get('/monitor/oauth/apple/callback')->assertRedirect('/monitor');

        $owner = MonitorUser::query()->where('email', 'owner@example.com')->firstOrFail();
        $this->assertSame($owner->id, Auth::guard(MonitorUser::guardName())->id());
    }

    public function test_apple_login_button_is_disabled_without_a_configured_client_id(): void
    {
        config(['monitor.auth.oauth.apple.client_id' => null]);
        $this->withoutMonitorAuth();

        $this->get('/monitor/login')->assertSeeText('Install laravel/socialite');
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `/opt/homebrew/bin/php vendor/bin/phpunit --filter=OAuthLoginTest`
Expected: FAIL — the `apple` driver isn't registered with Socialite yet, so `Socialite::fake('apple', ...)`/`driver('apple')` errors with an unsupported-driver exception.

- [ ] **Step 3: Register the Apple driver**

In `src/MonitorServiceProvider.php`, add to `boot()` (Apple's provider registration works via Socialite's own `SocialiteWasCalled` event, which needs a listener registered at boot time, not register time):

```php
    protected function registerAppleOAuthDriver(): void
    {
        if (! class_exists(\SocialiteProviders\Apple\Provider::class)) {
            return;
        }

        $this->app->make(\Illuminate\Contracts\Events\Dispatcher::class)->listen(
            \SocialiteProviders\Manager\SocialiteWasCalled::class,
            fn (\SocialiteProviders\Manager\SocialiteWasCalled $event) => $event->extendSocialite('apple', \SocialiteProviders\Apple\Provider::class),
        );
    }
```

Call it from `boot()`, next to the existing `Support\Settings::apply();` line:

```php
    public function boot(): void
    {
        Support\Settings::apply();
        $this->registerAppleOAuthDriver();
```

- [ ] **Step 4: Add the Apple login button**

In `resources/views/auth/login.blade.php`, after the Google button block added in Task 7:

```blade
                <a href="{{ \LaravelMonitor\Support\OptionalAuthMethod::oauthAvailable('apple') ? route('monitor.oauth.redirect', 'apple') : '#' }}"
                   class="mt-3 flex w-full items-center justify-center rounded-md border border-neutral-200 py-2 text-sm font-medium text-neutral-700 hover:bg-neutral-50 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800/50 {{ \LaravelMonitor\Support\OptionalAuthMethod::oauthAvailable('apple') ? '' : 'cursor-not-allowed opacity-50' }}">
                    Continue with Apple
                </a>
                @unless (\LaravelMonitor\Support\OptionalAuthMethod::oauthAvailable('apple'))
                    <p class="mt-1 text-center text-xs text-neutral-400 dark:text-neutral-500">Install <code class="font-mono">laravel/socialite socialiteproviders/apple</code> and configure <code class="font-mono">MONITOR_APPLE_CLIENT_ID</code> to enable this.</p>
                @endunless
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `/opt/homebrew/bin/php vendor/bin/phpunit --filter=OAuthLoginTest`
Expected: PASS.

- [ ] **Step 6: Syntax-check and run the full suite**

Run: `/opt/homebrew/bin/php -l resources/views/auth/login.blade.php`
Expected: `No syntax errors detected`.

Run: `/opt/homebrew/bin/php vendor/bin/phpunit`
Expected: PASS, 154 tests (152 + 2 new).

- [ ] **Step 7: Commit**

```bash
git add src/MonitorServiceProvider.php resources/views/auth/login.blade.php tests/OAuthLoginTest.php
git commit -m "feat: add OAuth login for Apple"
```
