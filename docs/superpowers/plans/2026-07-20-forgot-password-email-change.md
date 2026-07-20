# Forgot Password & Email-Change Approval Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give `laravel-monitor` self-service password reset (emailed link) and a
verify-then-approve email-change workflow, closing sub-project 3 of the
4-part user/team/auth decomposition.

**Architecture:** Two new flat lookup tables (`monitor_password_resets`,
`monitor_email_changes`) with SHA-256-hashed tokens, following the exact
`MonitorInvitation` pattern from sub-project 2. Two new plain controllers
(`PasswordResetController`, `EmailChangeController`) mirroring
`InvitationController`'s "own its own route, reachable unauthenticated"
shape. Email-change request/approve/reject live as three new methods on the
existing `Livewire\Team` component, wired into the existing
`team.blade.php`.

**Tech Stack:** Laravel (Eloquent, Mailable, session auth guard), Livewire 3/4,
Blade, Tailwind (CDN JIT, dark mode via `.dark`), PHPUnit + Orchestra
Testbench.

## Global Constraints

- Every PHP/PHPUnit/composer command MUST use `/opt/homebrew/bin/php`
  explicitly — never bare `php` (Herd's CLI php is broken on this machine).
- Tokens are stored hashed (`hash('sha256', $plainToken)`), never the plain
  value — same reason as `MonitorInvitation`: the row must be queryable by
  value from a single URL parameter, which rules out `Hash::make()`/bcrypt.
- Any route that lets an unauthenticated visitor consume a token via a
  **mutating** request (POST) must claim the token by deleting its row
  *before* doing the mutation it guards, so a double-submitted request
  can't race past the check twice (this exact bug was found and fixed in
  `InvitationController::store()` during sub-project 2's final review —
  apply the fix proactively here instead of waiting for a reviewer to find
  it again).
- A GET route must never mutate state that matters (e.g. consuming a
  one-time token) — matches `InvitationController`'s existing
  show()-then-store() split, and specifically guards against corporate
  email-security gateways that pre-fetch every link in a received email,
  which would otherwise silently consume a token before the real recipient
  ever clicks it.
- `<pre><code>...</code></pre>` must have zero whitespace between the tags
  if any new view uses one (none of this plan's views do, but check before
  adding one).
- New/modified Blade views must be syntax-checked with
  `/opt/homebrew/bin/php -l <file>` before being considered done.

---

### Task 1: Migrations + config

**Files:**
- Create: `database/migrations/2026_07_21_000001_create_monitor_password_resets_table.php`
- Create: `database/migrations/2026_07_21_000002_create_monitor_email_changes_table.php`
- Modify: `config/monitor.php`
- Test: `tests/MonitorTest.php`

**Interfaces:**
- Produces: tables `monitor_password_resets` (configurable via
  `monitor.auth.password_resets_table`) and `monitor_email_changes`
  (`monitor.auth.email_changes_table`), consumed by Task 2's models.

- [ ] **Step 1: Write the failing tests**

Add to `tests/MonitorTest.php` (anywhere alongside the existing
`test_monitor_invitations_table_exists_with_expected_columns` test):

```php
public function test_monitor_password_resets_table_exists_with_expected_columns(): void
{
    $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumns('monitor_password_resets', [
        'id', 'email', 'token', 'created_at', 'updated_at',
    ]));
}

public function test_monitor_email_changes_table_exists_with_expected_columns(): void
{
    $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumns('monitor_email_changes', [
        'id', 'user_id', 'new_email', 'token', 'verified_at', 'expires_at', 'created_at', 'updated_at',
    ]));
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `/opt/homebrew/bin/php vendor/bin/phpunit --filter=test_monitor_password_resets_table_exists|test_monitor_email_changes_table_exists`
Expected: FAIL — tables don't exist yet.

- [ ] **Step 3: Write the migrations**

`database/migrations/2026_07_21_000001_create_monitor_password_resets_table.php`:

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
        Schema::create($this->table(), function (Blueprint $table) {
            $table->id();
            $table->string('email');
            $table->string('token', 64)->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    protected function table(): string
    {
        return config('monitor.auth.password_resets_table', 'monitor_password_resets');
    }
};
```

`database/migrations/2026_07_21_000002_create_monitor_email_changes_table.php`:

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
        Schema::create($this->table(), function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('new_email');
            $table->string('token', 64)->unique();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    protected function table(): string
    {
        return config('monitor.auth.email_changes_table', 'monitor_email_changes');
    }
};
```

- [ ] **Step 4: Update config**

In `config/monitor.php`, change the `'auth'` array from:

```php
    'auth' => [
        'guard' => 'monitor',
        'table' => 'monitor_users',
        'invitations_table' => 'monitor_invitations',
    ],
```

to:

```php
    'auth' => [
        'guard' => 'monitor',
        'table' => 'monitor_users',
        'invitations_table' => 'monitor_invitations',
        'password_resets_table' => 'monitor_password_resets',
        'email_changes_table' => 'monitor_email_changes',
    ],
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `/opt/homebrew/bin/php vendor/bin/phpunit --filter=test_monitor_password_resets_table_exists|test_monitor_email_changes_table_exists`
Expected: PASS (both).

- [ ] **Step 6: Run the full suite**

Run: `/opt/homebrew/bin/php vendor/bin/phpunit`
Expected: PASS, 92 tests (90 + 2 new).

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_07_21_000001_create_monitor_password_resets_table.php database/migrations/2026_07_21_000002_create_monitor_email_changes_table.php config/monitor.php tests/MonitorTest.php
git commit -m "feat: add monitor_password_resets and monitor_email_changes tables"
```

---

### Task 2: Models

**Files:**
- Create: `src/Models/MonitorPasswordReset.php`
- Create: `src/Models/MonitorEmailChange.php`
- Test: `tests/MonitorTest.php`

**Interfaces:**
- Consumes: `monitor_password_resets`/`monitor_email_changes` tables (Task 1).
- Produces:
  - `MonitorPasswordReset::createFor(string $email): array{reset: self, plainToken: string}`
  - `MonitorPasswordReset::findByPlainToken(string $plainToken): ?self`
  - `MonitorPasswordReset::isExpired(): bool` (60-minute window from `created_at`)
  - `MonitorEmailChange::createFor(MonitorUser $requester, string $newEmail): array{emailChange: self, plainToken: string}`
  - `MonitorEmailChange::findByPlainToken(string $plainToken): ?self`
  - `MonitorEmailChange::isExpired(): bool` (60-minute window from `expires_at`)
  - `MonitorEmailChange::isVerified(): bool`
  - `MonitorEmailChange::user(): BelongsTo` — the requester

- [ ] **Step 1: Write the failing tests**

Add to `tests/MonitorTest.php`:

```php
public function test_monitor_password_reset_create_for_hashes_the_token_and_refreshes_on_repeat_request(): void
{
    ['reset' => $first, 'plainToken' => $firstToken] = \LaravelMonitor\Models\MonitorPasswordReset::createFor('reset-test@example.com');
    ['reset' => $second, 'plainToken' => $secondToken] = \LaravelMonitor\Models\MonitorPasswordReset::createFor('reset-test@example.com');

    $this->assertSame($first->id, $second->id, 'a repeat request should refresh the same row, not create a second one');
    $this->assertNotSame($firstToken, $secondToken);
    $this->assertNotSame($firstToken, $second->token, 'the stored token must be hashed, not the plain value');
    $this->assertNotNull(\LaravelMonitor\Models\MonitorPasswordReset::findByPlainToken($secondToken));
    $this->assertNull(\LaravelMonitor\Models\MonitorPasswordReset::findByPlainToken($firstToken), 'the old token must stop working once refreshed');
}

public function test_monitor_password_reset_is_expired_after_60_minutes(): void
{
    ['reset' => $reset] = \LaravelMonitor\Models\MonitorPasswordReset::createFor('expiry-test@example.com');
    $this->assertFalse($reset->isExpired());

    $reset->forceFill(['created_at' => now()->subMinutes(61)])->save();
    $this->assertTrue($reset->fresh()->isExpired());
}

public function test_monitor_email_change_create_for_hashes_the_token_and_is_unverified_by_default(): void
{
    $requester = \LaravelMonitor\Models\MonitorUser::where('email', 'owner@example.com')->firstOrFail();

    ['emailChange' => $emailChange, 'plainToken' => $plainToken] = \LaravelMonitor\Models\MonitorEmailChange::createFor($requester, 'new-address@example.com');

    $this->assertSame($requester->id, $emailChange->user_id);
    $this->assertSame('new-address@example.com', $emailChange->new_email);
    $this->assertNotSame($plainToken, $emailChange->token);
    $this->assertFalse($emailChange->isVerified());
    $this->assertNotNull(\LaravelMonitor\Models\MonitorEmailChange::findByPlainToken($plainToken));
    $this->assertSame($requester->id, $emailChange->user->id);
}

public function test_monitor_email_change_repeat_request_resets_verification(): void
{
    $requester = \LaravelMonitor\Models\MonitorUser::where('email', 'owner@example.com')->firstOrFail();

    ['emailChange' => $first] = \LaravelMonitor\Models\MonitorEmailChange::createFor($requester, 'first-address@example.com');
    $first->forceFill(['verified_at' => now()])->save();

    ['emailChange' => $second, 'plainToken' => $secondToken] = \LaravelMonitor\Models\MonitorEmailChange::createFor($requester, 'second-address@example.com');

    $this->assertSame($first->id, $second->id);
    $this->assertSame(1, \LaravelMonitor\Models\MonitorEmailChange::where('user_id', $requester->id)->count());
    $this->assertSame('second-address@example.com', $second->fresh()->new_email);
    $this->assertFalse($second->fresh()->isVerified(), 'requesting again must reset verification on the refreshed row');
    $this->assertNotNull(\LaravelMonitor\Models\MonitorEmailChange::findByPlainToken($secondToken));
}

public function test_monitor_email_change_is_expired_after_60_minutes(): void
{
    $requester = \LaravelMonitor\Models\MonitorUser::where('email', 'owner@example.com')->firstOrFail();
    ['emailChange' => $emailChange] = \LaravelMonitor\Models\MonitorEmailChange::createFor($requester, 'expiry-change-test@example.com');

    $this->assertFalse($emailChange->isExpired());

    $emailChange->forceFill(['expires_at' => now()->subHour()])->save();
    $this->assertTrue($emailChange->fresh()->isExpired());
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `/opt/homebrew/bin/php vendor/bin/phpunit --filter=test_monitor_password_reset|test_monitor_email_change`
Expected: FAIL with "Class not found".

- [ ] **Step 3: Write `MonitorPasswordReset`**

```php
<?php

namespace LaravelMonitor\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A one-time password-reset token. Hashed with SHA-256 (not
 * Hash::make()/bcrypt) for the same reason as MonitorInvitation::token —
 * it must be queryable by value from a single URL parameter.
 */
class MonitorPasswordReset extends Model
{
    protected $fillable = ['email', 'token'];

    public function getTable(): string
    {
        return config('monitor.auth.password_resets_table', 'monitor_password_resets');
    }

    public function getConnectionName(): ?string
    {
        return config('monitor.storage.database.connection');
    }

    /**
     * @return array{reset: self, plainToken: string}
     */
    public static function createFor(string $email): array
    {
        $plainToken = Str::random(40);

        $reset = static::query()->updateOrCreate(
            ['email' => $email],
            ['token' => hash('sha256', $plainToken)],
        );

        return ['reset' => $reset, 'plainToken' => $plainToken];
    }

    public static function findByPlainToken(string $plainToken): ?self
    {
        return static::query()->where('token', hash('sha256', $plainToken))->first();
    }

    public function isExpired(): bool
    {
        return $this->created_at->addMinutes(60)->isPast();
    }
}
```

- [ ] **Step 4: Write `MonitorEmailChange`**

```php
<?php

namespace LaravelMonitor\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A pending "change my email" request. verified_at is null until the
 * requester proves they control the new inbox by clicking the emailed
 * link; rows with verified_at still null are never shown to an
 * approver, regardless of role.
 */
class MonitorEmailChange extends Model
{
    protected $fillable = ['user_id', 'new_email', 'token', 'verified_at', 'expires_at'];

    protected $casts = ['verified_at' => 'datetime', 'expires_at' => 'datetime'];

    public function getTable(): string
    {
        return config('monitor.auth.email_changes_table', 'monitor_email_changes');
    }

    public function getConnectionName(): ?string
    {
        return config('monitor.storage.database.connection');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(MonitorUser::class, 'user_id');
    }

    /**
     * @return array{emailChange: self, plainToken: string}
     */
    public static function createFor(MonitorUser $requester, string $newEmail): array
    {
        $plainToken = Str::random(40);

        $emailChange = static::query()->updateOrCreate(
            ['user_id' => $requester->id],
            [
                'new_email' => $newEmail,
                'token' => hash('sha256', $plainToken),
                'verified_at' => null,
                'expires_at' => Carbon::now()->addMinutes(60),
            ],
        );

        return ['emailChange' => $emailChange, 'plainToken' => $plainToken];
    }

    public static function findByPlainToken(string $plainToken): ?self
    {
        return static::query()->where('token', hash('sha256', $plainToken))->first();
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }
}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `/opt/homebrew/bin/php vendor/bin/phpunit --filter=test_monitor_password_reset|test_monitor_email_change`
Expected: PASS (all 5).

- [ ] **Step 6: Run the full suite**

Run: `/opt/homebrew/bin/php vendor/bin/phpunit`
Expected: PASS, 97 tests (92 + 5 new).

- [ ] **Step 7: Commit**

```bash
git add src/Models/MonitorPasswordReset.php src/Models/MonitorEmailChange.php tests/MonitorTest.php
git commit -m "feat: add MonitorPasswordReset and MonitorEmailChange models"
```

---

### Task 3: Mail

**Files:**
- Create: `src/Mail/PasswordResetMail.php`
- Create: `src/Mail/EmailChangeVerificationMail.php`
- Create: `resources/views/mail/password-reset.blade.php`
- Create: `resources/views/mail/email-change-verification.blade.php`
- Test: `tests/MonitorTest.php`

**Interfaces:**
- Consumes: `MonitorPasswordReset`/`MonitorEmailChange` (Task 2, for token
  generation in tests only — the Mailables themselves only take a plain
  token string).
- Produces: `PasswordResetMail(string $plainToken)`,
  `EmailChangeVerificationMail(string $plainToken)`, both `Mailable`s
  rendering a view containing a link built from `$plainToken`.

- [ ] **Step 1: Write the failing tests**

Add to `tests/MonitorTest.php`:

```php
public function test_password_reset_mail_links_to_the_reset_url_with_the_plain_token(): void
{
    ['plainToken' => $plainToken] = \LaravelMonitor\Models\MonitorPasswordReset::createFor('mail-reset-test@example.com');

    $mail = new \LaravelMonitor\Mail\PasswordResetMail($plainToken);
    $rendered = $mail->render();

    $this->assertStringContainsString('/monitor/reset-password/'.$plainToken, $rendered);
}

public function test_email_change_verification_mail_links_to_the_verify_url_with_the_plain_token(): void
{
    $requester = \LaravelMonitor\Models\MonitorUser::where('email', 'owner@example.com')->firstOrFail();
    ['plainToken' => $plainToken] = \LaravelMonitor\Models\MonitorEmailChange::createFor($requester, 'mail-verify-test@example.com');

    $mail = new \LaravelMonitor\Mail\EmailChangeVerificationMail($plainToken);
    $rendered = $mail->render();

    $this->assertStringContainsString('/monitor/email-changes/'.$plainToken, $rendered);
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `/opt/homebrew/bin/php vendor/bin/phpunit --filter=test_password_reset_mail|test_email_change_verification_mail`
Expected: FAIL with "Class not found".

- [ ] **Step 3: Write `PasswordResetMail`**

```php
<?php

namespace LaravelMonitor\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PasswordResetMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public string $plainToken,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Reset your '.config('app.name', 'Laravel').' Monitor password',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'monitor::mail.password-reset',
            with: [
                'resetUrl' => url(trim(config('monitor.path', 'monitor'), '/').'/reset-password/'.$this->plainToken),
            ],
        );
    }
}
```

- [ ] **Step 4: Write `EmailChangeVerificationMail`**

```php
<?php

namespace LaravelMonitor\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class EmailChangeVerificationMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public string $plainToken,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Verify your new '.config('app.name', 'Laravel').' Monitor email',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'monitor::mail.email-change-verification',
            with: [
                'verifyUrl' => url(trim(config('monitor.path', 'monitor'), '/').'/email-changes/'.$this->plainToken),
            ],
        );
    }
}
```

- [ ] **Step 5: Write the mail views**

`resources/views/mail/password-reset.blade.php`:

```php
{{-- Password reset email. See Mail\PasswordResetMail. --}}
<p>Someone requested a password reset for your {{ config('app.name', 'Laravel') }} Monitor account.</p>
<p><a href="{{ $resetUrl }}">Reset your password</a></p>
<p>If you didn't request this, you can safely ignore this email.</p>
<p>This link expires in 60 minutes.</p>
```

`resources/views/mail/email-change-verification.blade.php`:

```php
{{-- Email-change verification email. See Mail\EmailChangeVerificationMail. --}}
<p>Confirm this is your email address to finish updating your {{ config('app.name', 'Laravel') }} Monitor account.</p>
<p><a href="{{ $verifyUrl }}">Verify this email address</a></p>
<p>This link expires in 60 minutes.</p>
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `/opt/homebrew/bin/php vendor/bin/phpunit --filter=test_password_reset_mail|test_email_change_verification_mail`
Expected: PASS (both).

- [ ] **Step 7: Syntax-check the new views**

Run:
```bash
/opt/homebrew/bin/php -l resources/views/mail/password-reset.blade.php
/opt/homebrew/bin/php -l resources/views/mail/email-change-verification.blade.php
```
Expected: `No syntax errors detected` for both.

- [ ] **Step 8: Run the full suite**

Run: `/opt/homebrew/bin/php vendor/bin/phpunit`
Expected: PASS, 99 tests (97 + 2 new).

- [ ] **Step 9: Commit**

```bash
git add src/Mail/PasswordResetMail.php src/Mail/EmailChangeVerificationMail.php resources/views/mail/password-reset.blade.php resources/views/mail/email-change-verification.blade.php tests/MonitorTest.php
git commit -m "feat: add password-reset and email-change-verification mail"
```

---

### Task 4: Forgot/reset password flow

**Files:**
- Create: `src/Http/Controllers/Auth/PasswordResetController.php`
- Create: `resources/views/auth/forgot-password.blade.php`
- Create: `resources/views/auth/reset-password.blade.php`
- Modify: `resources/views/auth/login.blade.php`
- Modify: `routes/web.php`
- Test: `tests/MonitorTest.php`

**Interfaces:**
- Consumes: `MonitorPasswordReset::createFor()`/`findByPlainToken()`/`isExpired()`
  (Task 2), `PasswordResetMail` (Task 3), `MonitorUser::guardName()`
  (existing).
- Produces: routes `monitor.password.request` (GET),
  `monitor.password.request.store` (POST), `monitor.password.reset` (GET),
  `monitor.password.reset.store` (POST) — all reachable without being
  authenticated.

- [ ] **Step 1: Write the failing tests**

Add to `tests/MonitorTest.php`:

```php
public function test_forgot_password_page_is_shown(): void
{
    Gate::define('viewMonitor', fn ($user = null) => true);
    $this->withoutMonitorAuth();

    $this->get('/monitor/forgot-password')->assertOk();
}

public function test_requesting_a_reset_for_a_known_email_sends_the_mail(): void
{
    Gate::define('viewMonitor', fn ($user = null) => true);
    $this->withoutMonitorAuth();
    \Illuminate\Support\Facades\Mail::fake();

    $this->post('/monitor/forgot-password', ['email' => 'owner@example.com'])->assertRedirect();

    \Illuminate\Support\Facades\Mail::assertSent(\LaravelMonitor\Mail\PasswordResetMail::class);
    $this->assertNotNull(\LaravelMonitor\Models\MonitorPasswordReset::where('email', 'owner@example.com')->first());
}

public function test_requesting_a_reset_for_an_unknown_email_sends_nothing_but_still_redirects_the_same_way(): void
{
    Gate::define('viewMonitor', fn ($user = null) => true);
    $this->withoutMonitorAuth();
    \Illuminate\Support\Facades\Mail::fake();

    $knownResponse = $this->post('/monitor/forgot-password', ['email' => 'owner@example.com']);
    $unknownResponse = $this->post('/monitor/forgot-password', ['email' => 'unknown-nobody@example.com']);

    $unknownResponse->assertRedirect();
    $this->assertSame($knownResponse->headers->get('Location'), $unknownResponse->headers->get('Location'), 'the response must not reveal whether the email exists');
    \Illuminate\Support\Facades\Mail::assertSent(\LaravelMonitor\Mail\PasswordResetMail::class, 1);
    $this->assertNull(\LaravelMonitor\Models\MonitorPasswordReset::where('email', 'unknown-nobody@example.com')->first());
}

public function test_reset_password_page_is_shown_for_a_valid_token(): void
{
    Gate::define('viewMonitor', fn ($user = null) => true);
    $this->withoutMonitorAuth();

    ['plainToken' => $plainToken] = \LaravelMonitor\Models\MonitorPasswordReset::createFor('owner@example.com');

    $this->get('/monitor/reset-password/'.$plainToken)->assertOk();
}

public function test_reset_password_returns_404_for_an_unknown_or_expired_token(): void
{
    Gate::define('viewMonitor', fn ($user = null) => true);
    $this->withoutMonitorAuth();

    $this->get('/monitor/reset-password/not-a-real-token')->assertNotFound();

    ['reset' => $reset, 'plainToken' => $expiredToken] = \LaravelMonitor\Models\MonitorPasswordReset::createFor('expired-reset-test@example.com');
    $reset->forceFill(['created_at' => now()->subMinutes(61)])->save();

    $this->get('/monitor/reset-password/'.$expiredToken)->assertNotFound();
}

public function test_resetting_the_password_updates_it_and_logs_the_user_in(): void
{
    Gate::define('viewMonitor', fn ($user = null) => true);
    $this->withoutMonitorAuth();

    $user = \LaravelMonitor\Models\MonitorUser::where('email', 'owner@example.com')->firstOrFail();
    ['plainToken' => $plainToken] = \LaravelMonitor\Models\MonitorPasswordReset::createFor('owner@example.com');

    $this->post('/monitor/reset-password/'.$plainToken, [
        'password' => 'new-password-123',
        'password_confirmation' => 'new-password-123',
    ])->assertRedirect('/monitor');

    $this->assertTrue(\Illuminate\Support\Facades\Auth::guard('monitor')->check());
    $this->assertSame($user->id, \Illuminate\Support\Facades\Auth::guard('monitor')->id());
    $this->assertTrue(\Illuminate\Support\Facades\Hash::check('new-password-123', $user->fresh()->password));
}

public function test_resetting_an_already_consumed_password_reset_token_returns_404_instead_of_erroring(): void
{
    Gate::define('viewMonitor', fn ($user = null) => true);
    $this->withoutMonitorAuth();

    ['plainToken' => $plainToken] = \LaravelMonitor\Models\MonitorPasswordReset::createFor('owner@example.com');

    $payload = ['password' => 'new-password-123', 'password_confirmation' => 'new-password-123'];

    $this->post('/monitor/reset-password/'.$plainToken, $payload)->assertRedirect('/monitor');
    $this->post('/monitor/reset-password/'.$plainToken, $payload)->assertNotFound();
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `/opt/homebrew/bin/php vendor/bin/phpunit --filter=test_forgot_password|test_requesting_a_reset|test_reset_password|test_resetting_the_password|test_resetting_an_already_consumed`
Expected: FAIL — routes don't exist yet.

- [ ] **Step 3: Write `PasswordResetController`**

```php
<?php

namespace LaravelMonitor\Http\Controllers\Auth;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use LaravelMonitor\Mail\PasswordResetMail;
use LaravelMonitor\Models\MonitorPasswordReset;
use LaravelMonitor\Models\MonitorUser;

class PasswordResetController
{
    public function showRequestForm(): View
    {
        return view('monitor::auth.forgot-password');
    }

    public function sendResetLink(Request $request): RedirectResponse
    {
        $validated = $request->validate(['email' => ['required', 'string', 'email']]);

        $user = MonitorUser::query()->where('email', $validated['email'])->first();

        if ($user !== null) {
            ['plainToken' => $plainToken] = MonitorPasswordReset::createFor($validated['email']);

            Mail::to($validated['email'])->send(new PasswordResetMail($plainToken));
        }

        return back()->with('status', 'If that email has an account, we’ve sent a password reset link.');
    }

    public function showResetForm(string $token): View
    {
        $reset = MonitorPasswordReset::findByPlainToken($token);

        abort_if($reset === null, 404);
        abort_if($reset->isExpired(), 404);

        return view('monitor::auth.reset-password', ['token' => $token]);
    }

    public function resetPassword(Request $request, string $token): RedirectResponse
    {
        $reset = MonitorPasswordReset::findByPlainToken($token);

        abort_if($reset === null, 404);
        abort_if($reset->isExpired(), 404);

        $validated = $request->validate([
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $claimed = MonitorPasswordReset::query()->where('id', $reset->id)->delete();

        abort_if($claimed === 0, 404);

        $user = MonitorUser::query()->where('email', $reset->email)->firstOrFail();
        $user->update(['password' => Hash::make($validated['password'])]);

        Auth::guard(MonitorUser::guardName())->login($user);

        return redirect()->route('monitor.dashboard');
    }
}
```

Note: `resetPassword()` deletes the `monitor_password_resets` row (the
atomic "claim") **before** updating the password, exactly like the fix
already applied to `InvitationController::store()` — a double-submitted
POST sees zero rows affected on its second attempt and gets a clean 404,
never a 500 from some downstream constraint.

- [ ] **Step 4: Write the forgot-password view**

`resources/views/auth/forgot-password.blade.php`:

```php
{{-- Forgot-password request page. See Http\Controllers\Auth\PasswordResetController. --}}
<x-monitor::layout title="Forgot password">
    <div class="flex min-h-screen items-center justify-center bg-neutral-50 px-4 dark:bg-neutral-950">
        <div class="w-full max-w-sm">
            <div class="mb-6 flex items-center justify-center gap-2.5">
                <span class="flex h-9 w-9 items-center justify-center rounded-md bg-neutral-900 text-sm font-semibold text-white dark:bg-neutral-700">{{ strtoupper(mb_substr(config('app.name', 'L'), 0, 1)) }}</span>
                <span class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">{{ config('app.name', 'Laravel') }} Monitor</span>
            </div>

            <div class="rounded-lg border border-neutral-200 bg-white p-6 shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                <h1 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">Forgot your password?</h1>
                <p class="mt-1 text-sm text-neutral-500 dark:text-neutral-400">Enter your email and we’ll send you a reset link.</p>

                @if (session('status'))
                    <div class="mt-4 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-600 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-400">
                        {{ session('status') }}
                    </div>
                @endif

                @if ($errors->any())
                    <div class="mt-4 rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-600 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-400">
                        {{ $errors->first() }}
                    </div>
                @endif

                <form method="POST" action="{{ route('monitor.password.request.store') }}" class="mt-4 space-y-4">
                    @csrf
                    <div>
                        <label for="email" class="block font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400">Email</label>
                        <input type="email" name="email" id="email" value="{{ old('email') }}" required autofocus
                               class="mt-1 w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-900 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100">
                    </div>
                    <button type="submit" class="w-full rounded-md bg-blue-600 py-2 text-sm font-medium text-white hover:bg-blue-500">Send reset link</button>
                </form>
            </div>
        </div>
    </div>
</x-monitor::layout>
```

- [ ] **Step 5: Write the reset-password view**

`resources/views/auth/reset-password.blade.php`:

```php
{{-- Password-reset page. See Http\Controllers\Auth\PasswordResetController. --}}
<x-monitor::layout title="Reset password">
    <div class="flex min-h-screen items-center justify-center bg-neutral-50 px-4 dark:bg-neutral-950">
        <div class="w-full max-w-sm">
            <div class="mb-6 flex items-center justify-center gap-2.5">
                <span class="flex h-9 w-9 items-center justify-center rounded-md bg-neutral-900 text-sm font-semibold text-white dark:bg-neutral-700">{{ strtoupper(mb_substr(config('app.name', 'L'), 0, 1)) }}</span>
                <span class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">{{ config('app.name', 'Laravel') }} Monitor</span>
            </div>

            <div class="rounded-lg border border-neutral-200 bg-white p-6 shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                <h1 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">Choose a new password</h1>

                @if ($errors->any())
                    <div class="mt-4 rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-600 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-400">
                        {{ $errors->first() }}
                    </div>
                @endif

                <form method="POST" action="{{ route('monitor.password.reset.store', $token) }}" class="mt-4 space-y-4">
                    @csrf
                    <div>
                        <label for="password" class="block font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400">New password</label>
                        <input type="password" name="password" id="password" required autofocus
                               class="mt-1 w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-900 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100">
                    </div>
                    <div>
                        <label for="password_confirmation" class="block font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400">Confirm new password</label>
                        <input type="password" name="password_confirmation" id="password_confirmation" required
                               class="mt-1 w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-900 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100">
                    </div>
                    <button type="submit" class="w-full rounded-md bg-blue-600 py-2 text-sm font-medium text-white hover:bg-blue-500">Reset password</button>
                </form>
            </div>
        </div>
    </div>
</x-monitor::layout>
```

- [ ] **Step 6: Add the "Forgot password?" link to the login page**

In `resources/views/auth/login.blade.php`, insert this immediately after
the closing `</form>` tag (still inside the card `<div>`):

```php
                <p class="mt-3 text-center text-sm text-neutral-500 dark:text-neutral-400">
                    <a href="{{ route('monitor.password.request') }}" class="text-blue-600 hover:underline dark:text-blue-400">Forgot your password?</a>
                </p>
```

- [ ] **Step 7: Register the routes**

In `routes/web.php`, add
`use LaravelMonitor\Http\Controllers\Auth\PasswordResetController;` to the
`use` block, and add these four lines right after the existing
`monitor.invitations.store` line, still inside the outer group but before
the inner `EnsureMonitorAuthenticated`-gated group:

```php
        Route::get('/forgot-password', [PasswordResetController::class, 'showRequestForm'])->name('monitor.password.request');
        Route::post('/forgot-password', [PasswordResetController::class, 'sendResetLink'])->name('monitor.password.request.store');
        Route::get('/reset-password/{token}', [PasswordResetController::class, 'showResetForm'])->name('monitor.password.reset');
        Route::post('/reset-password/{token}', [PasswordResetController::class, 'resetPassword'])->name('monitor.password.reset.store');
```

- [ ] **Step 8: Run tests to verify they pass**

Run: `/opt/homebrew/bin/php vendor/bin/phpunit --filter=test_forgot_password|test_requesting_a_reset|test_reset_password|test_resetting_the_password|test_resetting_an_already_consumed`
Expected: PASS (all 7).

- [ ] **Step 9: Syntax-check the new/modified views**

Run:
```bash
/opt/homebrew/bin/php -l resources/views/auth/forgot-password.blade.php
/opt/homebrew/bin/php -l resources/views/auth/reset-password.blade.php
/opt/homebrew/bin/php -l resources/views/auth/login.blade.php
```
Expected: `No syntax errors detected` for all three.

- [ ] **Step 10: Run the full suite**

Run: `/opt/homebrew/bin/php vendor/bin/phpunit`
Expected: PASS, 106 tests (99 + 7 new).

- [ ] **Step 11: Commit**

```bash
git add src/Http/Controllers/Auth/PasswordResetController.php resources/views/auth/forgot-password.blade.php resources/views/auth/reset-password.blade.php resources/views/auth/login.blade.php routes/web.php tests/MonitorTest.php
git commit -m "feat: add the forgot/reset password flow"
```

---

### Task 5: `Team::requestEmailChange()`

**Files:**
- Modify: `src/Livewire/Team.php`
- Modify: `tests/TeamTest.php`

**Interfaces:**
- Consumes: `MonitorEmailChange::createFor()` (Task 2),
  `EmailChangeVerificationMail` (Task 3).
- Produces: `Team::requestEmailChange(string $newEmail): void` — any role,
  for themself only; validates format and uniqueness, form-errors on
  `newEmail` on failure.

- [ ] **Step 1: Write the failing tests**

Add to `tests/TeamTest.php` (add
`use LaravelMonitor\Mail\EmailChangeVerificationMail;` and
`use LaravelMonitor\Models\MonitorEmailChange;` to the `use` block at the
top of the file):

```php
public function test_any_role_can_request_an_email_change_for_themself(): void
{
    Mail::fake();

    $viewer = MonitorUser::create([
        'name' => 'Viewer', 'email' => 'email-change-requester@example.com',
        'password' => Hash::make('password'), 'role' => 'viewer',
    ]);
    $this->actingAs($viewer, 'monitor');

    Livewire::test(Team::class)->call('requestEmailChange', 'new-for-viewer@example.com');

    $this->assertDatabaseHas('monitor_email_changes', ['user_id' => $viewer->id, 'new_email' => 'new-for-viewer@example.com']);
    Mail::assertSent(EmailChangeVerificationMail::class);
}

public function test_requesting_an_invalid_email_change_does_not_create_a_request(): void
{
    Mail::fake();

    Livewire::test(Team::class)->call('requestEmailChange', 'not-an-email')->assertHasErrors('newEmail');

    $this->assertSame(0, MonitorEmailChange::count());
    Mail::assertNothingSent();
}

public function test_requesting_an_email_change_to_an_email_already_in_use_is_rejected(): void
{
    Mail::fake();

    MonitorUser::create([
        'name' => 'Existing', 'email' => 'already-taken@example.com',
        'password' => Hash::make('password'), 'role' => 'viewer',
    ]);

    Livewire::test(Team::class)->call('requestEmailChange', 'already-taken@example.com')->assertHasErrors('newEmail');

    $this->assertSame(0, MonitorEmailChange::count());
    Mail::assertNothingSent();
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `/opt/homebrew/bin/php vendor/bin/phpunit --filter=test_any_role_can_request_an_email_change|test_requesting_an_invalid_email_change|test_requesting_an_email_change_to_an_email_already_in_use`
Expected: FAIL with "Method requestEmailChange does not exist".

- [ ] **Step 3: Add `requestEmailChange()` to `Team`**

In `src/Livewire/Team.php`, add these two `use` imports alongside the
existing ones:

```php
use LaravelMonitor\Mail\EmailChangeVerificationMail;
use LaravelMonitor\Models\MonitorEmailChange;
```

Then add this method (anywhere after `invite()` reads cleanly, e.g. right
after `cancelInvite()`):

```php
    public function requestEmailChange(string $newEmail): void
    {
        $actor = $this->actor();

        if (! filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
            $this->addError('newEmail', 'Please enter a valid email address.');

            return;
        }

        if (MonitorUser::query()->where('email', $newEmail)->where('id', '!=', $actor->id)->exists()) {
            $this->addError('newEmail', 'This email is already in use.');

            return;
        }

        ['plainToken' => $plainToken] = MonitorEmailChange::createFor($actor, $newEmail);

        Mail::to($newEmail)->send(new EmailChangeVerificationMail($plainToken));
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `/opt/homebrew/bin/php vendor/bin/phpunit --filter=test_any_role_can_request_an_email_change|test_requesting_an_invalid_email_change|test_requesting_an_email_change_to_an_email_already_in_use`
Expected: PASS (all 3).

- [ ] **Step 5: Run the full suite**

Run: `/opt/homebrew/bin/php vendor/bin/phpunit`
Expected: PASS, 109 tests (106 + 3 new).

- [ ] **Step 6: Commit**

```bash
git add src/Livewire/Team.php tests/TeamTest.php
git commit -m "feat: add Team::requestEmailChange"
```

---

### Task 6: Email-change verification flow

**Files:**
- Create: `src/Http/Controllers/Auth/EmailChangeController.php`
- Create: `resources/views/auth/email-change-verify.blade.php`
- Create: `resources/views/auth/email-change-verified.blade.php`
- Modify: `routes/web.php`
- Test: `tests/MonitorTest.php`

**Interfaces:**
- Consumes: `MonitorEmailChange::findByPlainToken()`/`isExpired()` (Task 2),
  `MonitorUser::isOwner()` (existing).
- Produces: routes `monitor.email-changes.show` (GET),
  `monitor.email-changes.store` (POST) — reachable without being
  authenticated, since clicking the link is itself the proof of inbox
  ownership.

- [ ] **Step 1: Write the failing tests**

Add to `tests/MonitorTest.php`:

```php
public function test_email_change_show_page_is_shown_for_a_valid_unverified_token(): void
{
    Gate::define('viewMonitor', fn ($user = null) => true);
    $this->withoutMonitorAuth();

    $owner = \LaravelMonitor\Models\MonitorUser::where('email', 'owner@example.com')->firstOrFail();
    ['plainToken' => $plainToken] = \LaravelMonitor\Models\MonitorEmailChange::createFor($owner, 'verify-show-test@example.com');

    $this->get('/monitor/email-changes/'.$plainToken)
        ->assertOk()
        ->assertSeeText('verify-show-test@example.com');
}

public function test_email_change_show_returns_404_for_an_unknown_or_expired_token(): void
{
    Gate::define('viewMonitor', fn ($user = null) => true);
    $this->withoutMonitorAuth();

    $this->get('/monitor/email-changes/not-a-real-token')->assertNotFound();

    $owner = \LaravelMonitor\Models\MonitorUser::where('email', 'owner@example.com')->firstOrFail();
    ['emailChange' => $emailChange, 'plainToken' => $expiredToken] = \LaravelMonitor\Models\MonitorEmailChange::createFor($owner, 'expired-verify-test@example.com');
    $emailChange->forceFill(['expires_at' => now()->subHour()])->save();

    $this->get('/monitor/email-changes/'.$expiredToken)->assertNotFound();
}

public function test_verifying_an_owners_email_change_applies_it_immediately(): void
{
    Gate::define('viewMonitor', fn ($user = null) => true);
    $this->withoutMonitorAuth();

    $owner = \LaravelMonitor\Models\MonitorUser::where('email', 'owner@example.com')->firstOrFail();
    ['emailChange' => $emailChange, 'plainToken' => $plainToken] = \LaravelMonitor\Models\MonitorEmailChange::createFor($owner, 'owner-new-email@example.com');

    $this->post('/monitor/email-changes/'.$plainToken)
        ->assertOk()
        ->assertSeeText('owner-new-email@example.com');

    $this->assertSame('owner-new-email@example.com', $owner->fresh()->email);
    $this->assertNull(\LaravelMonitor\Models\MonitorEmailChange::find($emailChange->id));
}

public function test_verifying_a_non_owners_email_change_leaves_it_pending_for_approval(): void
{
    Gate::define('viewMonitor', fn ($user = null) => true);
    $this->withoutMonitorAuth();

    $admin = \LaravelMonitor\Models\MonitorUser::create([
        'name' => 'Admin', 'email' => 'pending-change-admin@example.com',
        'password' => \Illuminate\Support\Facades\Hash::make('password'), 'role' => 'admin',
    ]);
    ['emailChange' => $emailChange, 'plainToken' => $plainToken] = \LaravelMonitor\Models\MonitorEmailChange::createFor($admin, 'admin-new-email@example.com');

    $this->post('/monitor/email-changes/'.$plainToken)->assertOk();

    $this->assertSame('pending-change-admin@example.com', $admin->fresh()->email, 'a non-owner change must not apply until approved');
    $this->assertNotNull($emailChange->fresh()->verified_at);
}

public function test_verifying_an_already_applied_email_change_returns_404(): void
{
    Gate::define('viewMonitor', fn ($user = null) => true);
    $this->withoutMonitorAuth();

    $owner = \LaravelMonitor\Models\MonitorUser::where('email', 'owner@example.com')->firstOrFail();
    ['plainToken' => $plainToken] = \LaravelMonitor\Models\MonitorEmailChange::createFor($owner, 'double-submit-verify@example.com');

    $this->post('/monitor/email-changes/'.$plainToken)->assertOk();
    $this->post('/monitor/email-changes/'.$plainToken)->assertNotFound();
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `/opt/homebrew/bin/php vendor/bin/phpunit --filter=test_email_change_show|test_verifying_an_owners_email_change|test_verifying_a_non_owners_email_change|test_verifying_an_already_applied_email_change`
Expected: FAIL — routes don't exist yet.

- [ ] **Step 3: Write `EmailChangeController`**

```php
<?php

namespace LaravelMonitor\Http\Controllers\Auth;

use Illuminate\Contracts\View\View;
use LaravelMonitor\Models\MonitorEmailChange;

class EmailChangeController
{
    public function show(string $token): View
    {
        $emailChange = MonitorEmailChange::findByPlainToken($token);

        abort_if($emailChange === null, 404);
        abort_if($emailChange->isExpired(), 404);

        return view('monitor::auth.email-change-verify', [
            'emailChange' => $emailChange,
            'token' => $token,
        ]);
    }

    public function store(string $token): View
    {
        $emailChange = MonitorEmailChange::findByPlainToken($token);

        abort_if($emailChange === null, 404);
        abort_if($emailChange->isExpired(), 404);

        $emailChange->forceFill(['verified_at' => now()])->save();

        $requester = $emailChange->user;
        $applied = false;
        $newEmail = $emailChange->new_email;

        if ($requester->isOwner()) {
            $requester->update(['email' => $newEmail]);
            $emailChange->delete();
            $applied = true;
        }

        return view('monitor::auth.email-change-verified', [
            'applied' => $applied,
            'newEmail' => $newEmail,
        ]);
    }
}
```

Note: this endpoint doesn't need the delete-first "claim" pattern from
`InvitationController::store()`/`PasswordResetController::resetPassword()`
— a second submit of a non-owner's token just re-sets `verified_at` to
`now()` again (harmless, idempotent), and a second submit of an owner's
already-applied token 404s cleanly on its own, since `findByPlainToken()`
returns null once the row is deleted (covered by
`test_verifying_an_already_applied_email_change_returns_404` above).

- [ ] **Step 4: Write the verify-confirmation view**

`resources/views/auth/email-change-verify.blade.php`:

```php
{{-- Email-change verification prompt. See Http\Controllers\Auth\EmailChangeController. --}}
<x-monitor::layout title="Verify email">
    <div class="flex min-h-screen items-center justify-center bg-neutral-50 px-4 dark:bg-neutral-950">
        <div class="w-full max-w-sm">
            <div class="mb-6 flex items-center justify-center gap-2.5">
                <span class="flex h-9 w-9 items-center justify-center rounded-md bg-neutral-900 text-sm font-semibold text-white dark:bg-neutral-700">{{ strtoupper(mb_substr(config('app.name', 'L'), 0, 1)) }}</span>
                <span class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">{{ config('app.name', 'Laravel') }} Monitor</span>
            </div>

            <div class="rounded-lg border border-neutral-200 bg-white p-6 shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                <h1 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">Confirm this email address</h1>
                <p class="mt-1 text-sm text-neutral-500 dark:text-neutral-400">Confirm that <strong>{{ $emailChange->new_email }}</strong> belongs to you.</p>

                <form method="POST" action="{{ route('monitor.email-changes.store', $token) }}" class="mt-4">
                    @csrf
                    <button type="submit" class="w-full rounded-md bg-blue-600 py-2 text-sm font-medium text-white hover:bg-blue-500">Confirm this is my email</button>
                </form>
            </div>
        </div>
    </div>
</x-monitor::layout>
```

- [ ] **Step 5: Write the verified-result view**

`resources/views/auth/email-change-verified.blade.php`:

```php
{{-- Email-change verification result. See Http\Controllers\Auth\EmailChangeController. --}}
<x-monitor::layout title="Email verified">
    <div class="flex min-h-screen items-center justify-center bg-neutral-50 px-4 dark:bg-neutral-950">
        <div class="w-full max-w-sm">
            <div class="mb-6 flex items-center justify-center gap-2.5">
                <span class="flex h-9 w-9 items-center justify-center rounded-md bg-neutral-900 text-sm font-semibold text-white dark:bg-neutral-700">{{ strtoupper(mb_substr(config('app.name', 'L'), 0, 1)) }}</span>
                <span class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">{{ config('app.name', 'Laravel') }} Monitor</span>
            </div>

            <div class="rounded-lg border border-neutral-200 bg-white p-6 shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                @if ($applied)
                    <h1 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">Email updated</h1>
                    <p class="mt-1 text-sm text-neutral-500 dark:text-neutral-400">Your account email is now <strong>{{ $newEmail }}</strong>.</p>
                @else
                    <h1 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">Email verified</h1>
                    <p class="mt-1 text-sm text-neutral-500 dark:text-neutral-400">Thanks — <strong>{{ $newEmail }}</strong> is verified. An owner or admin needs to approve the change before it takes effect.</p>
                @endif
            </div>
        </div>
    </div>
</x-monitor::layout>
```

- [ ] **Step 6: Register the routes**

In `routes/web.php`, add
`use LaravelMonitor\Http\Controllers\Auth\EmailChangeController;` to the
`use` block, and add these two lines right after the four
`monitor.password.*` lines added in Task 4, still inside the outer group
but before the inner `EnsureMonitorAuthenticated`-gated group:

```php
        Route::get('/email-changes/{token}', [EmailChangeController::class, 'show'])->name('monitor.email-changes.show');
        Route::post('/email-changes/{token}', [EmailChangeController::class, 'store'])->name('monitor.email-changes.store');
```

- [ ] **Step 7: Run tests to verify they pass**

Run: `/opt/homebrew/bin/php vendor/bin/phpunit --filter=test_email_change_show|test_verifying_an_owners_email_change|test_verifying_a_non_owners_email_change|test_verifying_an_already_applied_email_change`
Expected: PASS (all 5).

- [ ] **Step 8: Syntax-check the new views**

Run:
```bash
/opt/homebrew/bin/php -l resources/views/auth/email-change-verify.blade.php
/opt/homebrew/bin/php -l resources/views/auth/email-change-verified.blade.php
```
Expected: `No syntax errors detected` for both.

- [ ] **Step 9: Run the full suite**

Run: `/opt/homebrew/bin/php vendor/bin/phpunit`
Expected: PASS, 114 tests (109 + 5 new).

- [ ] **Step 10: Commit**

```bash
git add src/Http/Controllers/Auth/EmailChangeController.php resources/views/auth/email-change-verify.blade.php resources/views/auth/email-change-verified.blade.php routes/web.php tests/MonitorTest.php
git commit -m "feat: add the email-change verification flow"
```

---

### Task 7: `Team::approveEmailChange()` / `rejectEmailChange()`

**Files:**
- Modify: `src/Livewire/Team.php`
- Modify: `tests/TeamTest.php`

**Interfaces:**
- Consumes: `MonitorEmailChange` (Task 2), `MonitorUser::isOwner()`/`canManageTeam()`
  (existing).
- Produces: `Team::approveEmailChange(int $emailChangeId): void`,
  `Team::rejectEmailChange(int $emailChangeId): void`. Both `abort(403)`
  unless the actor outranks the *requester's current role*: requester is
  `admin` → actor must be owner; requester is `viewer` → actor must
  satisfy `canManageTeam()`. Both are no-ops (return silently, no error)
  if the request isn't verified yet or doesn't exist.

- [ ] **Step 1: Write the failing tests**

Add to `tests/TeamTest.php`:

```php
public function test_owner_can_approve_an_admins_verified_email_change(): void
{
    $admin = MonitorUser::create([
        'name' => 'Admin', 'email' => 'approve-admin-test@example.com',
        'password' => Hash::make('password'), 'role' => 'admin',
    ]);
    ['emailChange' => $emailChange] = MonitorEmailChange::createFor($admin, 'admin-approved-new@example.com');
    $emailChange->forceFill(['verified_at' => now()])->save();

    Livewire::test(Team::class)->call('approveEmailChange', $emailChange->id);

    $this->assertSame('admin-approved-new@example.com', $admin->fresh()->email);
    $this->assertNull(MonitorEmailChange::find($emailChange->id));
}

public function test_admin_cannot_approve_another_admins_verified_email_change(): void
{
    $requestingAdmin = MonitorUser::create([
        'name' => 'Requesting Admin', 'email' => 'requesting-admin-test@example.com',
        'password' => Hash::make('password'), 'role' => 'admin',
    ]);
    $decidingAdmin = MonitorUser::create([
        'name' => 'Deciding Admin', 'email' => 'deciding-admin-test@example.com',
        'password' => Hash::make('password'), 'role' => 'admin',
    ]);
    ['emailChange' => $emailChange] = MonitorEmailChange::createFor($requestingAdmin, 'blocked-admin-new@example.com');
    $emailChange->forceFill(['verified_at' => now()])->save();
    $this->actingAs($decidingAdmin, 'monitor');

    Livewire::test(Team::class)->call('approveEmailChange', $emailChange->id)->assertForbidden();

    $this->assertSame('requesting-admin-test@example.com', $requestingAdmin->fresh()->email);
}

public function test_owner_or_admin_can_approve_a_viewers_verified_email_change(): void
{
    $viewer = MonitorUser::create([
        'name' => 'Viewer', 'email' => 'approve-viewer-test@example.com',
        'password' => Hash::make('password'), 'role' => 'viewer',
    ]);
    ['emailChange' => $emailChange] = MonitorEmailChange::createFor($viewer, 'viewer-approved-new@example.com');
    $emailChange->forceFill(['verified_at' => now()])->save();

    $admin = MonitorUser::create([
        'name' => 'Approving Admin', 'email' => 'approving-admin-test@example.com',
        'password' => Hash::make('password'), 'role' => 'admin',
    ]);
    $this->actingAs($admin, 'monitor');

    Livewire::test(Team::class)->call('approveEmailChange', $emailChange->id);

    $this->assertSame('viewer-approved-new@example.com', $viewer->fresh()->email);
}

public function test_viewer_cannot_approve_or_reject_another_viewers_email_change(): void
{
    $requestingViewer = MonitorUser::create([
        'name' => 'Requesting Viewer', 'email' => 'requesting-viewer-test@example.com',
        'password' => Hash::make('password'), 'role' => 'viewer',
    ]);
    ['emailChange' => $emailChange] = MonitorEmailChange::createFor($requestingViewer, 'blocked-viewer-new@example.com');
    $emailChange->forceFill(['verified_at' => now()])->save();

    $decidingViewer = MonitorUser::create([
        'name' => 'Deciding Viewer', 'email' => 'deciding-viewer-test@example.com',
        'password' => Hash::make('password'), 'role' => 'viewer',
    ]);
    $this->actingAs($decidingViewer, 'monitor');

    Livewire::test(Team::class)->call('approveEmailChange', $emailChange->id)->assertForbidden();
    Livewire::test(Team::class)->call('rejectEmailChange', $emailChange->id)->assertForbidden();

    $this->assertNotNull(MonitorEmailChange::find($emailChange->id));
}

public function test_rejecting_an_email_change_deletes_it_without_changing_the_email(): void
{
    $admin = MonitorUser::create([
        'name' => 'Admin', 'email' => 'reject-admin-test@example.com',
        'password' => Hash::make('password'), 'role' => 'admin',
    ]);
    ['emailChange' => $emailChange] = MonitorEmailChange::createFor($admin, 'rejected-new-email@example.com');
    $emailChange->forceFill(['verified_at' => now()])->save();

    Livewire::test(Team::class)->call('rejectEmailChange', $emailChange->id);

    $this->assertSame('reject-admin-test@example.com', $admin->fresh()->email);
    $this->assertNull(MonitorEmailChange::find($emailChange->id));
}

public function test_approving_an_unverified_email_change_does_nothing(): void
{
    $admin = MonitorUser::create([
        'name' => 'Admin', 'email' => 'unverified-admin-test@example.com',
        'password' => Hash::make('password'), 'role' => 'admin',
    ]);
    ['emailChange' => $emailChange] = MonitorEmailChange::createFor($admin, 'unverified-new@example.com');

    Livewire::test(Team::class)->call('approveEmailChange', $emailChange->id);

    $this->assertSame('unverified-admin-test@example.com', $admin->fresh()->email);
    $this->assertNotNull(MonitorEmailChange::find($emailChange->id), 'an unverified request must not be silently deleted either');
}

public function test_approving_an_email_change_whose_target_email_was_claimed_meanwhile_fails_cleanly(): void
{
    $admin = MonitorUser::create([
        'name' => 'Admin', 'email' => 'race-admin-test@example.com',
        'password' => Hash::make('password'), 'role' => 'admin',
    ]);
    ['emailChange' => $emailChange] = MonitorEmailChange::createFor($admin, 'claimed-meanwhile@example.com');
    $emailChange->forceFill(['verified_at' => now()])->save();

    // Someone else claims the target email between verification and approval.
    MonitorUser::create([
        'name' => 'Someone Else', 'email' => 'claimed-meanwhile@example.com',
        'password' => Hash::make('password'), 'role' => 'viewer',
    ]);

    Livewire::test(Team::class)->call('approveEmailChange', $emailChange->id)->assertHasErrors('emailChange');

    $this->assertSame('race-admin-test@example.com', $admin->fresh()->email, 'approval must not overwrite the requester\'s email once the target is taken');
    $this->assertNotNull(MonitorEmailChange::find($emailChange->id), 'the pending row must survive a failed approval so it can be re-decided');
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `/opt/homebrew/bin/php vendor/bin/phpunit --filter=test_owner_can_approve|test_admin_cannot_approve_another|test_owner_or_admin_can_approve|test_viewer_cannot_approve_or_reject|test_rejecting_an_email_change|test_approving_an_unverified_email_change|test_approving_an_email_change_whose_target_email_was_claimed_meanwhile`
Expected: FAIL with "Method approveEmailChange does not exist".

- [ ] **Step 3: Add `approveEmailChange()` and `rejectEmailChange()` to `Team`**

In `src/Livewire/Team.php`, add these two methods (after
`requestEmailChange()`):

```php
    public function approveEmailChange(int $emailChangeId): void
    {
        $actor = $this->actor();
        $emailChange = MonitorEmailChange::query()->find($emailChangeId);

        if ($emailChange === null || ! $emailChange->isVerified()) {
            return;
        }

        $requester = $emailChange->user;

        if (! $this->canDecideEmailChange($actor, $requester)) {
            abort(403);
        }

        if (MonitorUser::query()->where('email', $emailChange->new_email)->where('id', '!=', $requester->id)->exists()) {
            $this->addError('emailChange', 'That email is no longer available.');

            return;
        }

        $requester->update(['email' => $emailChange->new_email]);
        $emailChange->delete();
    }

    public function rejectEmailChange(int $emailChangeId): void
    {
        $actor = $this->actor();
        $emailChange = MonitorEmailChange::query()->find($emailChangeId);

        if ($emailChange === null || ! $emailChange->isVerified()) {
            return;
        }

        if (! $this->canDecideEmailChange($actor, $emailChange->user)) {
            abort(403);
        }

        $emailChange->delete();
    }

    protected function canDecideEmailChange(MonitorUser $actor, MonitorUser $requester): bool
    {
        return match ($requester->role) {
            'admin' => $actor->isOwner(),
            'viewer' => $actor->canManageTeam(),
            default => false,
        };
    }
```

The `default => false` arm matters: it means an email-change row
belonging to an `owner`-role requester (which shouldn't exist in practice
— an owner's request auto-applies at verification and is deleted
immediately, see Task 6) is never approvable/rejectable by anyone,
including another owner, rather than silently falling through to "allowed"
if the two explicit `match` arms don't cover every role.

- [ ] **Step 4: Run tests to verify they pass**

Run: `/opt/homebrew/bin/php vendor/bin/phpunit --filter=test_owner_can_approve|test_admin_cannot_approve_another|test_owner_or_admin_can_approve|test_viewer_cannot_approve_or_reject|test_rejecting_an_email_change|test_approving_an_unverified_email_change|test_approving_an_email_change_whose_target_email_was_claimed_meanwhile`
Expected: PASS (all 7).

- [ ] **Step 5: Run the full suite**

Run: `/opt/homebrew/bin/php vendor/bin/phpunit`
Expected: PASS, 121 tests (114 + 7 new).

- [ ] **Step 6: Commit**

```bash
git add src/Livewire/Team.php tests/TeamTest.php
git commit -m "feat: add Team::approveEmailChange and rejectEmailChange"
```

---

### Task 8: Wire email-change UI into `team.blade.php`

**Files:**
- Modify: `src/Livewire/Team.php`
- Modify: `resources/views/livewire/team.blade.php`
- Modify: `tests/TeamTest.php`

**Interfaces:**
- Consumes: `Team::requestEmailChange()` (Task 5),
  `Team::approveEmailChange()`/`rejectEmailChange()` (Task 7).
- Produces: an unconditional "Change your email" form and a "Pending
  email changes" section (verified rows only) on the Team page, with
  Approve/Reject visible only per the same role rule enforced server-side
  in Task 7.

- [ ] **Step 1: Write the failing test**

Add to `tests/TeamTest.php`:

```php
public function test_an_unverified_email_change_never_appears_in_pending_email_changes(): void
{
    $admin = MonitorUser::create([
        'name' => 'Admin', 'email' => 'unverified-visibility-test@example.com',
        'password' => Hash::make('password'), 'role' => 'admin',
    ]);
    MonitorEmailChange::createFor($admin, 'not-yet-verified@example.com');

    $component = Livewire::test(Team::class);

    $this->assertTrue($component->viewData('pendingEmailChanges')->isEmpty());
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `/opt/homebrew/bin/php vendor/bin/phpunit --filter=test_an_unverified_email_change_never_appears_in_pending_email_changes`
Expected: FAIL — `viewData('pendingEmailChanges')` doesn't exist yet
(`Team::data()` has no such key).

- [ ] **Step 3: Add `pendingEmailChanges` to `Team::data()`**

In `src/Livewire/Team.php`, change `data()` from:

```php
    protected function data(): array
    {
        return [
            'members' => MonitorUser::query()->orderBy('created_at')->get(),
            'pendingInvitations' => MonitorInvitation::query()
                ->where('expires_at', '>', now())
                ->orderByDesc('created_at')
                ->get(),
        ];
    }
```

to:

```php
    protected function data(): array
    {
        return [
            'members' => MonitorUser::query()->orderBy('created_at')->get(),
            'pendingInvitations' => MonitorInvitation::query()
                ->where('expires_at', '>', now())
                ->orderByDesc('created_at')
                ->get(),
            'pendingEmailChanges' => MonitorEmailChange::query()
                ->whereNotNull('verified_at')
                ->with('user')
                ->orderByDesc('created_at')
                ->get(),
        ];
    }
```

- [ ] **Step 4: Add the "Change email" form and "Pending email changes" section**

In `resources/views/livewire/team.blade.php`, add this closure to the
top `@php` block, alongside the existing `$roleBadge`:

```php
    $canDecideEmailChange = fn ($requester) => match ($requester->role) {
        'admin' => $actor->isOwner(),
        'viewer' => $actor->canManageTeam(),
        default => false,
    };
```

Then, immediately after the closing `@endif` of the existing "Invite a
member" card (the `@if ($actor->canManageTeam())` block) and before the
`@if ($pendingInvitations->isNotEmpty())` block, insert:

```php
        <x-monitor::card class="p-4">
            <p class="font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400">Change your email</p>
            <form wire:submit="requestEmailChange($refs.newEmail.value)" class="mt-3 flex flex-wrap items-end gap-2" x-data>
                <div class="min-w-0 flex-1">
                    <label class="block font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400">New email</label>
                    <input type="email" x-ref="newEmail" required
                           class="mt-1 w-full rounded-md border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 px-2 py-1.5 text-sm text-neutral-900 dark:text-neutral-100 focus:outline-none">
                </div>
                <button type="submit" class="h-8 rounded-md bg-blue-600 px-3 text-sm font-medium text-white hover:bg-blue-500">Send verification email</button>
            </form>
            @error('newEmail')
                <p class="mt-2 text-sm text-rose-600 dark:text-rose-400">{{ $message }}</p>
            @enderror
        </x-monitor::card>
```

And, immediately after the `@endif` that closes the "Pending Invitations"
`@if ($pendingInvitations->isNotEmpty())` block (and before the "Members"
heading), insert:

```php
        @if ($pendingEmailChanges->isNotEmpty())
            <div class="mt-4 flex items-center gap-2 px-1 pb-3">
                <h3 class="font-semibold text-neutral-900 dark:text-neutral-100">{{ number_format($pendingEmailChanges->count()) }} Pending Email {{ $pendingEmailChanges->count() === 1 ? 'Change' : 'Changes' }}</h3>
            </div>
            <x-monitor::card class="p-4">
                <div class="divide-y divide-neutral-100 dark:divide-neutral-800">
                    @foreach ($pendingEmailChanges as $emailChange)
                        <div class="flex items-center gap-3 py-2.5">
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-medium text-neutral-900 dark:text-neutral-100">{{ $emailChange->user->name }}</p>
                                <p class="truncate font-mono text-xs text-neutral-400 dark:text-neutral-500">{{ $emailChange->user->email }} &rarr; {{ $emailChange->new_email }}</p>
                            </div>
                            @if ($canDecideEmailChange($emailChange->user))
                                <button type="button" wire:click="approveEmailChange({{ $emailChange->id }})"
                                        class="shrink-0 rounded-md border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 px-2 py-1 font-mono text-[10px] uppercase tracking-tight text-neutral-500 dark:text-neutral-400 shadow-sm hover:bg-neutral-50 dark:hover:bg-neutral-800/50">Approve</button>
                                <button type="button" wire:click="rejectEmailChange({{ $emailChange->id }})" wire:confirm="Reject this email change?"
                                        class="shrink-0 rounded-md border border-rose-200 dark:border-rose-500/30 bg-white dark:bg-neutral-900 px-2 py-1 font-mono text-[10px] uppercase tracking-tight text-rose-600 dark:text-rose-400 shadow-sm hover:bg-rose-50 dark:hover:bg-rose-500/10">Reject</button>
                            @endif
                        </div>
                    @endforeach
                </div>
                @error('emailChange')
                    <p class="mt-3 text-sm text-rose-600 dark:text-rose-400">{{ $message }}</p>
                @enderror
            </x-monitor::card>
        @endif
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `/opt/homebrew/bin/php vendor/bin/phpunit --filter=test_an_unverified_email_change_never_appears_in_pending_email_changes`
Expected: PASS.

- [ ] **Step 6: Syntax-check the modified view**

Run: `/opt/homebrew/bin/php -l resources/views/livewire/team.blade.php`
Expected: `No syntax errors detected`.

- [ ] **Step 7: Run the full suite**

Run: `/opt/homebrew/bin/php vendor/bin/phpunit`
Expected: PASS, 122 tests (121 + 1 new).

- [ ] **Step 8: Commit**

```bash
git add src/Livewire/Team.php resources/views/livewire/team.blade.php tests/TeamTest.php
git commit -m "feat: wire email-change request and approval into the Team view"
```

---

### Task 9: Final verification

**Files:** none (verification only)

- [ ] **Step 1: Run the full suite one more time from a clean state**

Run: `/opt/homebrew/bin/php vendor/bin/phpunit`
Expected: PASS, 122 tests, 0 failures, pristine output.

- [ ] **Step 2: Syntax-check every new/modified Blade view**

Run:
```bash
/opt/homebrew/bin/php -l resources/views/auth/forgot-password.blade.php
/opt/homebrew/bin/php -l resources/views/auth/reset-password.blade.php
/opt/homebrew/bin/php -l resources/views/auth/login.blade.php
/opt/homebrew/bin/php -l resources/views/auth/email-change-verify.blade.php
/opt/homebrew/bin/php -l resources/views/auth/email-change-verified.blade.php
/opt/homebrew/bin/php -l resources/views/livewire/team.blade.php
/opt/homebrew/bin/php -l resources/views/mail/password-reset.blade.php
/opt/homebrew/bin/php -l resources/views/mail/email-change-verification.blade.php
```
Expected: `No syntax errors detected` for all eight.

- [ ] **Step 3: Whole-branch review**

Dispatch a whole-branch code-review pass (base: `git merge-base master HEAD`,
head: `HEAD`) covering all commits from Task 1 through Task 8 on top of
whatever's already on `feat/team-auth-management`. Specifically re-check,
since these are the two riskiest spots in this sub-project:
- The `canDecideEmailChange()` permission matrix (Task 7) — confirm no
  role/actor combination can approve or reject a request it shouldn't,
  including the `owner`-requester edge case that should never reach this
  method at all.
- Every unauthenticated-reachable POST endpoint added in this
  sub-project (`sendResetLink`, `resetPassword`,
  `EmailChangeController::store`) for the same double-submit-race class
  of bug fixed in sub-project 2's `InvitationController::store()`.

Independently verify any findings before acting on them (re-read the
actual code, don't take a review verdict at face value) — same standard
applied at the end of sub-project 2.

- [ ] **Step 4: Report status**

This closes sub-project 3/4 (Forgot password & email-change approval).
Report the final test count and ask whether to push/open a PR, or
continue straight to sub-project 4 (OAuth/passkey/TOTP login methods).
