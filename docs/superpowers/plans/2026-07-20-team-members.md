# Team & Members Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let the dashboard's owner/admin invite people by email, and let the resulting members manage their own team — accept an invite, get promoted/demoted, get removed, leave, or (owner only) transfer ownership.

**Architecture:** A new `monitor_invitations` table (separate from `monitor_users` — a pending invite is not yet a real account) backed by a `MonitorInvitation` model that generates a random plain token, stores only its SHA-256 hash (queryable-by-value but never reversible — the same reason Laravel's own API-token packages hash this way instead of bcrypt, which can't be looked up by value), and emails the plain token as a link. A new `Livewire\Team` component (extends the existing `Card` base class like every other dashboard tab, even though it doesn't use the period picker, to stay consistent with how every Livewire tab in this package is registered/dispatched) lists members and pending invites with inline actions. Accepting an invite is a plain controller (same "owns its own simple form, no Livewire needed" pattern as `SetupController`/`LoginController`), reachable without being authenticated.

**Tech Stack:** Laravel 10–13, Livewire 3/4, Blade + Tailwind (no build step), PHPUnit 10+, Laravel's built-in `Mail`/`Mailable` (no new Composer dependency — this is the package's first outbound email, sent through whatever mail driver the *host app* has configured).

## Global Constraints

- Support Laravel 10 through 13 (same floor as the rest of this package).
- Migration `getConnection()` pattern: return `config('monitor.storage.database.connection')`, matching every existing migration in `database/migrations/`.
- PHP conventions: curly braces on every control structure, PHP 8 constructor property promotion where a class has a constructor, explicit return/parameter type hints on every method.
- **This machine's Herd PHP CLI is broken** (missing dylib) — run every PHP/PHPUnit command with `/opt/homebrew/bin/php` explicitly, never bare `php`.
- Baseline on this branch (`feat/team-auth-management`): **62 tests, 204 assertions, all green** (Foundation sub-project, already merged into this branch's history). Every task must leave the suite green.
- `tests/TestCase.php::setUp()` already auto-seeds and logs in a default `owner`-role `MonitorUser` (`owner@example.com` / password `password`) for every test, and exposes `$this->withoutMonitorAuth(): static` to opt out. Tests in this plan that need a *different* role (`admin`, `viewer`) or a *second* member create their own `MonitorUser` and call `$this->actingAs($user, 'monitor')` to override the default, exactly like the existing Foundation tests already do (e.g. `test_a_viewer_cannot_post_settings_system` in `tests/MonitorTest.php`).
- Do not commit unless a task step explicitly says to.

---

### Task 1: `monitor_invitations` migration + config

**Files:**
- Create: `database/migrations/2026_07_20_000001_create_monitor_invitations_table.php`
- Modify: `config/monitor.php`
- Test: `tests/MonitorTest.php`

**Interfaces:**
- Produces: table `monitor_invitations` (configurable name via
  `config('monitor.auth.invitations_table', 'monitor_invitations')`) with
  columns `id`, `email`, `role` (string(16)), `token` (string(64), unique
  — a SHA-256 hex digest is 64 chars), `invited_by` (unsigned big
  integer, no DB-level FK — this package never uses real FK constraints,
  e.g. `monitor_issues` doesn't FK to `monitor_entries` either),
  `expires_at` (timestamp), timestamps.

- [ ] **Step 1: Write the failing test**

Add to `tests/MonitorTest.php`:

```php
public function test_monitor_invitations_table_exists_with_expected_columns(): void
{
    $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumns('monitor_invitations', [
        'id', 'email', 'role', 'token', 'invited_by', 'expires_at', 'created_at', 'updated_at',
    ]));
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `/opt/homebrew/bin/php vendor/bin/phpunit --filter=test_monitor_invitations_table_exists_with_expected_columns`
Expected: FAIL — table `monitor_invitations` doesn't exist yet.

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
        Schema::create($this->invitationsTable(), function (Blueprint $table) {
            $table->id();
            $table->string('email');
            $table->string('role', 16);
            $table->string('token', 64)->unique();
            $table->unsignedBigInteger('invited_by');
            $table->timestamp('expires_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->invitationsTable());
    }

    protected function invitationsTable(): string
    {
        return config('monitor.auth.invitations_table', 'monitor_invitations');
    }
};
```

- [ ] **Step 4: Add the config key**

In `config/monitor.php`, inside the existing `'auth' => [...]` array (added
in the Foundation sub-project), add one line so the array becomes:

```php
    'auth' => [
        'guard' => 'monitor',
        'table' => 'monitor_users',
        'invitations_table' => 'monitor_invitations',
    ],
```

- [ ] **Step 5: Run test to verify it passes**

Run: `/opt/homebrew/bin/php vendor/bin/phpunit --filter=test_monitor_invitations_table_exists_with_expected_columns`
Expected: PASS

- [ ] **Step 6: Run the full suite**

Run: `/opt/homebrew/bin/php vendor/bin/phpunit`
Expected: PASS, 63 tests.

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_07_20_000001_create_monitor_invitations_table.php config/monitor.php tests/MonitorTest.php
git commit -m "feat: add monitor_invitations table"
```

---

### Task 2: `MonitorInvitation` model + `MonitorUser` role helpers

**Files:**
- Create: `src/Models/MonitorInvitation.php`
- Modify: `src/Models/MonitorUser.php`
- Test: `tests/MonitorTest.php`

**Interfaces:**
- Consumes: `monitor_invitations` table (Task 1).
- Produces:
  - `LaravelMonitor\Models\MonitorInvitation` — Eloquent model. Static
    `createFor(string $email, string $role, MonitorUser $inviter): array{invitation: MonitorInvitation, plainToken: string}`
    (generates a random 40-char token, stores its SHA-256 hash, sets
    `expires_at` 2 hours out, upserts by `email` so re-inviting refreshes
    rather than duplicates — see Step 3 for the exact upsert logic).
    Static `findByPlainToken(string $plainToken): ?self` (hashes the
    given token and looks it up — this is how a hashed-at-rest token can
    still be looked up by a single URL parameter, unlike bcrypt which is
    salted and can't be reverse-queried). Instance `isExpired(): bool`.
  - `MonitorUser::isOwner(): bool` (didn't exist yet — Foundation
    deliberately left it out since it had no caller then; this plan is
    its first real caller, for the sole-owner-leave-block and
    transfer-ownership logic in Task 8).
  - `MonitorUser::canManageTeam(): bool` (owner/admin — same role set as
    `canManageSettings()` today, but kept as its own method rather than
    reusing that one, since "can invite people" and "can change app
    settings" are different concerns that happen to currently share a
    role set and shouldn't be coupled just because of that).

- [ ] **Step 1: Write the failing tests**

Add to `tests/MonitorTest.php`:

```php
public function test_monitor_user_gains_isowner_and_canmanageteam_helpers(): void
{
    $owner = \LaravelMonitor\Models\MonitorUser::create([
        'name' => 'Owner', 'email' => 'owner-helpers-test@example.com',
        'password' => \Illuminate\Support\Facades\Hash::make('password'), 'role' => 'owner',
    ]);
    $admin = \LaravelMonitor\Models\MonitorUser::create([
        'name' => 'Admin', 'email' => 'admin-helpers-test@example.com',
        'password' => \Illuminate\Support\Facades\Hash::make('password'), 'role' => 'admin',
    ]);
    $viewer = \LaravelMonitor\Models\MonitorUser::create([
        'name' => 'Viewer', 'email' => 'viewer-helpers-test@example.com',
        'password' => \Illuminate\Support\Facades\Hash::make('password'), 'role' => 'viewer',
    ]);

    $this->assertTrue($owner->isOwner());
    $this->assertFalse($admin->isOwner());
    $this->assertFalse($viewer->isOwner());

    $this->assertTrue($owner->canManageTeam());
    $this->assertTrue($admin->canManageTeam());
    $this->assertFalse($viewer->canManageTeam());
}

public function test_monitor_invitation_create_for_generates_a_findable_token_and_expires_in_two_hours(): void
{
    $inviter = \LaravelMonitor\Models\MonitorUser::where('email', 'owner@example.com')->firstOrFail();

    ['invitation' => $invitation, 'plainToken' => $plainToken] = \LaravelMonitor\Models\MonitorInvitation::createFor('invitee@example.com', 'viewer', $inviter);

    $this->assertSame('invitee@example.com', $invitation->email);
    $this->assertSame('viewer', $invitation->role);
    $this->assertSame($inviter->id, $invitation->invited_by);
    $this->assertNotSame($plainToken, $invitation->token, 'the stored token must be hashed, not the plain value');
    $this->assertTrue($invitation->expires_at->between(now()->addMinutes(119), now()->addMinutes(121)));
    $this->assertFalse($invitation->isExpired());

    $found = \LaravelMonitor\Models\MonitorInvitation::findByPlainToken($plainToken);
    $this->assertNotNull($found);
    $this->assertSame($invitation->id, $found->id);

    $this->assertNull(\LaravelMonitor\Models\MonitorInvitation::findByPlainToken('not-a-real-token'));
}

public function test_monitor_invitation_create_for_refreshes_an_existing_pending_invite_to_the_same_email(): void
{
    $firstInviter = \LaravelMonitor\Models\MonitorUser::where('email', 'owner@example.com')->firstOrFail();
    $secondInviter = \LaravelMonitor\Models\MonitorUser::create([
        'name' => 'Second Admin', 'email' => 'second-inviter-test@example.com',
        'password' => \Illuminate\Support\Facades\Hash::make('password'), 'role' => 'admin',
    ]);

    ['invitation' => $first] = \LaravelMonitor\Models\MonitorInvitation::createFor('re-invited@example.com', 'viewer', $firstInviter);
    ['invitation' => $second, 'plainToken' => $secondToken] = \LaravelMonitor\Models\MonitorInvitation::createFor('re-invited@example.com', 'admin', $secondInviter);

    $this->assertSame($first->id, $second->id, 'refreshing should update the same row, not create a second one');
    $this->assertSame(1, \LaravelMonitor\Models\MonitorInvitation::where('email', 're-invited@example.com')->count());
    $this->assertSame('admin', $second->fresh()->role);
    $this->assertSame($secondInviter->id, $second->fresh()->invited_by);
    $this->assertNotNull(\LaravelMonitor\Models\MonitorInvitation::findByPlainToken($secondToken));
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `/opt/homebrew/bin/php vendor/bin/phpunit --filter=test_monitor_user_gains|test_monitor_invitation`
Expected: FAIL — `MonitorInvitation` class and `isOwner()`/`canManageTeam()` don't exist.

- [ ] **Step 3: Write `MonitorInvitation`**

```php
<?php

namespace LaravelMonitor\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A pending invite — deliberately its own table, not a "pending" row in
 * monitor_users, since a person who hasn't accepted yet isn't a real
 * account (no password, shouldn't be listed as a member, shouldn't be
 * assignable settings/team permissions).
 *
 * The token is hashed with SHA-256 (not Hash::make()/bcrypt) specifically
 * because it must be *queryable by value* from a single URL parameter —
 * bcrypt is salted and can't be looked up that way, which is exactly why
 * Laravel's own API-token-style packages use the same SHA-256 pattern
 * instead of the framework's usual password hashing.
 */
class MonitorInvitation extends Model
{
    protected $fillable = ['email', 'role', 'token', 'invited_by', 'expires_at'];

    protected $casts = ['expires_at' => 'datetime'];

    public function getTable(): string
    {
        return config('monitor.auth.invitations_table', 'monitor_invitations');
    }

    public function getConnectionName(): ?string
    {
        return config('monitor.storage.database.connection');
    }

    /**
     * @return array{invitation: self, plainToken: string}
     */
    public static function createFor(string $email, string $role, MonitorUser $inviter): array
    {
        $plainToken = Str::random(40);

        $invitation = static::query()->updateOrCreate(
            ['email' => $email],
            [
                'role' => $role,
                'token' => hash('sha256', $plainToken),
                'invited_by' => $inviter->id,
                'expires_at' => Carbon::now()->addHours(2),
            ],
        );

        return ['invitation' => $invitation, 'plainToken' => $plainToken];
    }

    public static function findByPlainToken(string $plainToken): ?self
    {
        return static::query()->where('token', hash('sha256', $plainToken))->first();
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}
```

- [ ] **Step 4: Add the two methods to `MonitorUser`**

In `src/Models/MonitorUser.php`, add both methods right after
`canManageSettings()`:

```php
    public function isOwner(): bool
    {
        return $this->role === 'owner';
    }

    public function canManageTeam(): bool
    {
        return in_array($this->role, ['owner', 'admin'], true);
    }
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `/opt/homebrew/bin/php vendor/bin/phpunit --filter=test_monitor_user_gains|test_monitor_invitation`
Expected: PASS (all 3)

- [ ] **Step 6: Run the full suite**

Run: `/opt/homebrew/bin/php vendor/bin/phpunit`
Expected: PASS, 66 tests.

- [ ] **Step 7: Commit**

```bash
git add src/Models/MonitorInvitation.php src/Models/MonitorUser.php tests/MonitorTest.php
git commit -m "feat: add MonitorInvitation model and MonitorUser role helpers"
```

---

### Task 3: Invitation email

**Files:**
- Create: `src/Mail/TeamInvitationMail.php`
- Create: `resources/views/mail/invitation.blade.php`
- Test: `tests/MonitorTest.php`

**Interfaces:**
- Consumes: `MonitorInvitation` (Task 2) plus the plain token (the model
  only ever stores the hash, so the plain token has to be threaded
  through explicitly from wherever `createFor()` was called).
- Produces: `LaravelMonitor\Mail\TeamInvitationMail` — a `Mailable`
  constructed with the invitation and its plain token, rendering a link
  to `/monitor/invitations/{plainToken}` (that route is registered in
  Task 9 — this task only builds the mail content, doesn't send anything
  yet; Task 6 is what actually calls `Mail::to(...)->send(...)`).

- [ ] **Step 1: Write the failing test**

Add to `tests/MonitorTest.php`:

```php
public function test_team_invitation_mail_links_to_the_accept_url_with_the_plain_token(): void
{
    $inviter = \LaravelMonitor\Models\MonitorUser::where('email', 'owner@example.com')->firstOrFail();
    ['invitation' => $invitation, 'plainToken' => $plainToken] = \LaravelMonitor\Models\MonitorInvitation::createFor('mail-test@example.com', 'viewer', $inviter);

    $mail = new \LaravelMonitor\Mail\TeamInvitationMail($invitation, $plainToken);
    $rendered = $mail->render();

    $this->assertStringContainsString('/monitor/invitations/'.$plainToken, $rendered);
    $this->assertStringContainsString($inviter->name, $rendered);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `/opt/homebrew/bin/php vendor/bin/phpunit --filter=test_team_invitation_mail_links_to_the_accept_url_with_the_plain_token`
Expected: FAIL — `LaravelMonitor\Mail\TeamInvitationMail` doesn't exist.

- [ ] **Step 3: Write the Mailable**

```php
<?php

namespace LaravelMonitor\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use LaravelMonitor\Models\MonitorInvitation;

class TeamInvitationMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public MonitorInvitation $invitation,
        public string $plainToken,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'You’ve been invited to '.config('app.name', 'Laravel').' Monitor',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'monitor::mail.invitation',
            with: [
                'inviterName' => $this->invitation->invitedBy()?->name ?? 'A team member',
                'role' => $this->invitation->role,
                'acceptUrl' => url(trim(config('monitor.path', 'monitor'), '/').'/invitations/'.$this->plainToken),
            ],
        );
    }
}
```

Note: `$this->invitation->invitedBy()` is used above for a friendlier
sender name in the email body, but `MonitorInvitation` doesn't have that
relationship yet — add it now, in the same file edited by Task 2 but not
covered by this task's own interface list, since it's purely a
convenience accessor with no test of its own beyond what this Mailable
test already exercises indirectly. In `src/Models/MonitorInvitation.php`,
add:

```php
    public function invitedBy(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(MonitorUser::class, 'invited_by');
    }
```

(Add this as a new method on the class, anywhere after `getConnectionName()`.)

- [ ] **Step 4: Write the mail view**

```php
{{-- Team invitation email. See Mail\TeamInvitationMail. --}}
<p>{{ $inviterName }} invited you to join the {{ config('app.name', 'Laravel') }} Monitor dashboard as <strong>{{ ucfirst($role) }}</strong>.</p>
<p><a href="{{ $acceptUrl }}">Accept the invitation</a></p>
<p>This link expires in 2 hours.</p>
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `/opt/homebrew/bin/php vendor/bin/phpunit --filter=test_team_invitation_mail_links_to_the_accept_url_with_the_plain_token`
Expected: PASS

- [ ] **Step 6: Run the full suite**

Run: `/opt/homebrew/bin/php vendor/bin/phpunit`
Expected: PASS, 67 tests.

- [ ] **Step 7: Commit**

```bash
git add src/Mail/TeamInvitationMail.php resources/views/mail/invitation.blade.php src/Models/MonitorInvitation.php tests/MonitorTest.php
git commit -m "feat: add the team invitation email"
```

---

### Task 4: "Team" nav entry + icon + period-picker hide

**Files:**
- Modify: `src/Support/Icons.php`
- Modify: `src/Support/Nav.php`
- Modify: `resources/lang/en/messages.php`
- Modify: `resources/lang/vi/messages.php`
- Modify: `resources/views/components/header.blade.php`
- Test: `tests/MonitorTest.php`

**Interfaces:**
- Produces: `Icons::TEAM` constant; `Nav::tabs()` gains a `'team'` entry
  (footer group, next to `'settings'`, matching how team-management and
  app-settings are both "administer this installation" concerns rather
  than "browse monitored data" ones); the header's period-picker no
  longer renders on the Team tab (same treatment `'settings'` already
  gets).

- [ ] **Step 1: Write the failing test**

Add to `tests/MonitorTest.php`:

```php
public function test_team_tab_is_registered_in_the_footer_group(): void
{
    [, $footer] = \LaravelMonitor\Support\Nav::grouped();

    $this->assertArrayHasKey('team', $footer);
    $this->assertSame('monitor.team', $footer['team']['component']);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `/opt/homebrew/bin/php vendor/bin/phpunit --filter=test_team_tab_is_registered_in_the_footer_group`
Expected: FAIL — no `'team'` key in `Nav::tabs()`.

- [ ] **Step 3: Add the icon**

In `src/Support/Icons.php`, add this constant (a standard "add a person"
glyph — the same one Heroicons ships as `user-plus`):

```php
    public const TEAM = 'M18 7.5v3m0 0v3m0-3h3m-3 0h-3m-2.25-4.125a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0ZM3 19.235v-.11a6.375 6.375 0 0 1 12.75 0v.109A12.318 12.318 0 0 1 9.375 21c-2.331 0-4.512-.645-6.375-1.766Z';
```

- [ ] **Step 4: Add the `'team'` tab to `Nav::tabs()`**

In `src/Support/Nav.php`, add this line right before the `'settings'`
entry (so it sits next to it in the footer group):

```php
            'team' => ['label' => __('monitor::messages.nav.team'), 'group' => 'footer', 'icon' => Icons::TEAM, 'component' => 'monitor.team'],
```

- [ ] **Step 5: Add the translation keys**

In `resources/lang/en/messages.php`, inside the `'nav' => [...]` array,
add (right after `'users' => 'Users',`):

```php
        'team' => 'Team',
```

In `resources/lang/vi/messages.php`, inside its own `'nav' => [...]`
array, add (same position):

```php
        'team' => 'Nhóm',
```

- [ ] **Step 6: Hide the period picker on the Team tab**

In `resources/views/components/header.blade.php`, change:

```php
        @if ($tab !== 'settings')
```

to:

```php
        @if (! in_array($tab, ['settings', 'team'], true))
```

- [ ] **Step 7: Run test to verify it passes**

Run: `/opt/homebrew/bin/php vendor/bin/phpunit --filter=test_team_tab_is_registered_in_the_footer_group`
Expected: PASS

- [ ] **Step 8: Run the full suite**

Run: `/opt/homebrew/bin/php vendor/bin/phpunit`
Expected: PASS, 68 tests. (The dashboard route for `tab=team` will 500 at
this point since `Livewire::component('monitor.team', ...)` isn't
registered yet — that's fine, nothing in this task or the existing suite
visits that URL. Task 6 registers it.)

- [ ] **Step 9: Commit**

```bash
git add src/Support/Icons.php src/Support/Nav.php resources/lang/en/messages.php resources/lang/vi/messages.php resources/views/components/header.blade.php tests/MonitorTest.php
git commit -m "feat: add the Team nav entry"
```

---

### Task 5: `Livewire\Team` — list members and pending invites, invite, cancel invite

**Files:**
- Create: `src/Livewire/Team.php`
- Modify: `src/MonitorServiceProvider.php`
- Test: `tests/TeamTest.php` (new file — this feature area is big enough to warrant its own test file rather than growing `tests/MonitorTest.php` further, matching how `tests/IssuesTest.php` already exists as its own file for the Issues feature)

**Interfaces:**
- Consumes: `MonitorUser`/`MonitorInvitation` (Task 2),
  `TeamInvitationMail` (Task 3), `Card` base class (existing).
- Produces: `Livewire\Team extends Card`, registered as
  `Livewire::component('monitor.team', Cards\Team::class)`. Public
  methods `invite(string $email, string $role): void` and
  `cancelInvite(int $invitationId): void`. `data()` returns `members`
  (all `MonitorUser` rows) and `pendingInvitations` (all
  non-expired `MonitorInvitation` rows). This task does NOT yet include
  role-change/remove/leave/transfer-ownership — those are Task 7. It also
  does NOT yet include the Blade view — that's Task 6 (the view needs
  what this task's `data()` produces, so building the view first would
  have nothing real to render against).

- [ ] **Step 1: Write the failing tests**

Create `tests/TeamTest.php`:

```php
<?php

namespace LaravelMonitor\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use LaravelMonitor\Livewire\Team;
use LaravelMonitor\Mail\TeamInvitationMail;
use LaravelMonitor\Models\MonitorInvitation;
use LaravelMonitor\Models\MonitorUser;
use Livewire\Livewire;

class TeamTest extends TestCase
{
    use RefreshDatabase;

    public function test_data_lists_members_and_pending_invitations(): void
    {
        $viewer = MonitorUser::create([
            'name' => 'Existing Viewer', 'email' => 'existing-viewer@example.com',
            'password' => Hash::make('password'), 'role' => 'viewer',
        ]);
        $owner = MonitorUser::where('email', 'owner@example.com')->firstOrFail();
        MonitorInvitation::createFor('pending@example.com', 'admin', $owner);

        $component = Livewire::test(Team::class);

        $memberEmails = $component->viewData('members')->pluck('email')->all();
        $this->assertContains('owner@example.com', $memberEmails);
        $this->assertContains($viewer->email, $memberEmails);

        $invitationEmails = $component->viewData('pendingInvitations')->pluck('email')->all();
        $this->assertContains('pending@example.com', $invitationEmails);
    }

    public function test_owner_can_invite_a_new_member_and_an_email_is_sent(): void
    {
        Mail::fake();

        Livewire::test(Team::class)->call('invite', 'new-member@example.com', 'viewer');

        $this->assertDatabaseHas('monitor_invitations', ['email' => 'new-member@example.com', 'role' => 'viewer']);
        Mail::assertSent(TeamInvitationMail::class, fn ($mail) => $mail->invitation->email === 'new-member@example.com');
    }

    public function test_admin_can_invite_a_new_member(): void
    {
        Mail::fake();

        $admin = MonitorUser::create([
            'name' => 'Admin', 'email' => 'inviting-admin@example.com',
            'password' => Hash::make('password'), 'role' => 'admin',
        ]);
        $this->actingAs($admin, 'monitor');

        Livewire::test(Team::class)->call('invite', 'admin-invited@example.com', 'viewer');

        $this->assertDatabaseHas('monitor_invitations', ['email' => 'admin-invited@example.com']);
    }

    public function test_viewer_cannot_invite(): void
    {
        Mail::fake();

        $viewer = MonitorUser::create([
            'name' => 'Viewer', 'email' => 'non-inviting-viewer@example.com',
            'password' => Hash::make('password'), 'role' => 'viewer',
        ]);
        $this->actingAs($viewer, 'monitor');

        Livewire::test(Team::class)->call('invite', 'blocked@example.com', 'viewer');

        $this->assertDatabaseMissing('monitor_invitations', ['email' => 'blocked@example.com']);
        Mail::assertNothingSent();
    }

    public function test_inviting_an_existing_members_email_does_not_create_an_invitation(): void
    {
        Mail::fake();

        Livewire::test(Team::class)->call('invite', 'owner@example.com', 'viewer');

        $this->assertSame(0, MonitorInvitation::where('email', 'owner@example.com')->count());
        Mail::assertNothingSent();
    }

    public function test_owner_can_cancel_any_pending_invitation(): void
    {
        $admin = MonitorUser::create([
            'name' => 'Admin', 'email' => 'cancel-test-admin@example.com',
            'password' => Hash::make('password'), 'role' => 'admin',
        ]);
        ['invitation' => $invitation] = MonitorInvitation::createFor('cancel-me@example.com', 'viewer', $admin);

        Livewire::test(Team::class)->call('cancelInvite', $invitation->id);

        $this->assertDatabaseMissing('monitor_invitations', ['id' => $invitation->id]);
    }

    public function test_admin_can_only_cancel_invitations_they_sent_themselves(): void
    {
        $owner = MonitorUser::where('email', 'owner@example.com')->firstOrFail();
        ['invitation' => $ownersInvitation] = MonitorInvitation::createFor('owners-invite@example.com', 'viewer', $owner);

        $admin = MonitorUser::create([
            'name' => 'Admin', 'email' => 'own-invite-admin@example.com',
            'password' => Hash::make('password'), 'role' => 'admin',
        ]);
        ['invitation' => $adminsInvitation] = MonitorInvitation::createFor('admins-invite@example.com', 'viewer', $admin);
        $this->actingAs($admin, 'monitor');

        Livewire::test(Team::class)->call('cancelInvite', $ownersInvitation->id);
        $this->assertDatabaseHas('monitor_invitations', ['id' => $ownersInvitation->id]);

        Livewire::test(Team::class)->call('cancelInvite', $adminsInvitation->id);
        $this->assertDatabaseMissing('monitor_invitations', ['id' => $adminsInvitation->id]);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `/opt/homebrew/bin/php vendor/bin/phpunit --filter=TeamTest`
Expected: FAIL — `LaravelMonitor\Livewire\Team` doesn't exist, and it
isn't registered as `monitor.team` yet.

- [ ] **Step 3: Write `Livewire\Team`**

```php
<?php

namespace LaravelMonitor\Livewire;

use Illuminate\Support\Facades\Mail;
use LaravelMonitor\Mail\TeamInvitationMail;
use LaravelMonitor\Models\MonitorInvitation;
use LaravelMonitor\Models\MonitorUser;

class Team extends Card
{
    protected function view(): string
    {
        return 'monitor::livewire.team';
    }

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

    public function invite(string $email, string $role): void
    {
        $actor = $this->actor();

        if (! $actor->canManageTeam()) {
            abort(403);
        }

        if (! in_array($role, ['admin', 'viewer'], true)) {
            return;
        }

        if (MonitorUser::query()->where('email', $email)->exists()) {
            $this->addError('email', 'This email is already a member.');

            return;
        }

        ['invitation' => $invitation, 'plainToken' => $plainToken] = MonitorInvitation::createFor($email, $role, $actor);

        Mail::to($email)->send(new TeamInvitationMail($invitation, $plainToken));
    }

    public function cancelInvite(int $invitationId): void
    {
        $actor = $this->actor();
        $invitation = MonitorInvitation::query()->find($invitationId);

        if ($invitation === null) {
            return;
        }

        if (! $actor->isOwner() && $invitation->invited_by !== $actor->id) {
            abort(403);
        }

        $invitation->delete();
    }

    protected function actor(): MonitorUser
    {
        return request()->user(MonitorUser::guardName());
    }
}
```

- [ ] **Step 4: Register the Livewire component**

In `src/MonitorServiceProvider.php`, don't add a new `use` statement —
every component is already referenced as `Cards\ComponentName::class`
via the existing `use LaravelMonitor\Livewire as Cards;` import, so
`Team` follows that same convention. In `registerLivewireComponents()`,
add this line right after the `monitor.logs` line, before
`monitor.users`:

```php
        Livewire::component('monitor.team', Cards\Team::class);
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `/opt/homebrew/bin/php vendor/bin/phpunit --filter=TeamTest`
Expected: PASS (all 7)

- [ ] **Step 6: Run the full suite**

Run: `/opt/homebrew/bin/php vendor/bin/phpunit`
Expected: PASS, 75 tests (68 + 7 new).

- [ ] **Step 7: Commit**

```bash
git add src/Livewire/Team.php src/MonitorServiceProvider.php tests/TeamTest.php
git commit -m "feat: add the Team Livewire component (list, invite, cancel invite)"
```

---

### Task 6: `team.blade.php` view (member list, invite form, pending invites)

**Files:**
- Create: `resources/views/livewire/team.blade.php`

**Interfaces:**
- Consumes: `$members`, `$pendingInvitations` (Task 5's `data()`),
  `invite()`/`cancelInvite()` (Task 5's public methods),
  `$request->user('monitor')` (for permission-gating which buttons
  render — the Livewire methods themselves already enforce permissions
  server-side; hiding buttons the visitor can't use is a UX nicety, not
  the actual security boundary).

- [ ] **Step 1: Write the view**

```php
@php
    use LaravelMonitor\Support\Icons;

    $actor = request()->user(\LaravelMonitor\Models\MonitorUser::guardName());
    $roleBadge = fn (string $role) => match ($role) {
        'owner' => 'border-blue-200 dark:border-blue-500/30 bg-blue-50 dark:bg-blue-500/10 text-blue-600 dark:text-blue-400',
        'admin' => 'border-amber-200 dark:border-amber-500/30 bg-amber-50 dark:bg-amber-500/10 text-amber-600 dark:text-amber-400',
        default => 'border-neutral-200 dark:border-neutral-700 bg-neutral-100/80 dark:bg-neutral-800/80 text-neutral-500 dark:text-neutral-400',
    };
@endphp
<div wire:poll.{{ $refresh }}s>
    <x-monitor::section :icon="Icons::TEAM" title="Team">
        @if ($actor->canManageTeam())
            <x-monitor::card class="p-4">
                <p class="font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400">Invite a member</p>
                <form wire:submit="invite($refs.email.value, $refs.role.value)" class="mt-3 flex flex-wrap items-end gap-2" x-data>
                    <div class="min-w-0 flex-1">
                        <label class="block font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400">Email</label>
                        <input type="email" x-ref="email" required
                               class="mt-1 w-full rounded-md border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 px-2 py-1.5 text-sm text-neutral-900 dark:text-neutral-100 focus:outline-none">
                    </div>
                    <div>
                        <label class="block font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400">Role</label>
                        <select x-ref="role" class="mt-1 rounded-md border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 px-2 py-1.5 text-sm text-neutral-900 dark:text-neutral-100 focus:outline-none">
                            <option value="viewer">Viewer</option>
                            <option value="admin">Admin</option>
                        </select>
                    </div>
                    <button type="submit" class="h-8 rounded-md bg-blue-600 px-3 text-sm font-medium text-white hover:bg-blue-500">Send invite</button>
                </form>
                @error('email')
                    <p class="mt-2 text-sm text-rose-600 dark:text-rose-400">{{ $message }}</p>
                @enderror
            </x-monitor::card>
        @endif

        @if ($pendingInvitations->isNotEmpty())
            <div class="mt-4 flex items-center gap-2 px-1 pb-3">
                <h3 class="font-semibold text-neutral-900 dark:text-neutral-100">{{ number_format($pendingInvitations->count()) }} Pending {{ $pendingInvitations->count() === 1 ? 'Invite' : 'Invites' }}</h3>
            </div>
            <x-monitor::card class="p-4">
                <div class="divide-y divide-neutral-100 dark:divide-neutral-800">
                    @foreach ($pendingInvitations as $invitation)
                        <div class="flex items-center gap-3 py-2.5">
                            <span class="min-w-0 flex-1 truncate font-mono text-sm text-neutral-700 dark:text-neutral-200">{{ $invitation->email }}</span>
                            <span class="shrink-0 rounded border px-1.5 py-0.5 font-mono text-[10px] uppercase tracking-tight {{ $roleBadge($invitation->role) }}">{{ $invitation->role }}</span>
                            <span class="shrink-0 font-mono text-xs text-neutral-400 dark:text-neutral-500">expires {{ $invitation->expires_at->diffForHumans() }}</span>
                            @if ($actor->isOwner() || $invitation->invited_by === $actor->id)
                                <button type="button" wire:click="cancelInvite({{ $invitation->id }})"
                                        class="shrink-0 rounded-md border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 px-2 py-1 font-mono text-[10px] uppercase tracking-tight text-neutral-500 dark:text-neutral-400 shadow-sm hover:bg-neutral-50 dark:hover:bg-neutral-800/50">Cancel</button>
                            @endif
                        </div>
                    @endforeach
                </div>
            </x-monitor::card>
        @endif

        <div class="mt-4 flex items-center gap-2 px-1 pb-3">
            <h3 class="font-semibold text-neutral-900 dark:text-neutral-100">{{ number_format($members->count()) }} {{ $members->count() === 1 ? 'Member' : 'Members' }}</h3>
        </div>
        <x-monitor::card class="p-4">
            <div class="divide-y divide-neutral-100 dark:divide-neutral-800">
                @foreach ($members as $member)
                    <div class="flex items-center gap-3 py-2.5">
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium text-neutral-900 dark:text-neutral-100">{{ $member->name }}</p>
                            <p class="truncate font-mono text-xs text-neutral-400 dark:text-neutral-500">{{ $member->email }}</p>
                        </div>
                        <span class="shrink-0 rounded border px-1.5 py-0.5 font-mono text-[10px] uppercase tracking-tight {{ $roleBadge($member->role) }}">{{ $member->role }}</span>
                    </div>
                @endforeach
            </div>
        </x-monitor::card>
    </x-monitor::section>
</div>
```

Note: role-change/remove/leave/transfer buttons intentionally aren't in
this version of the view — Task 8 adds them once the Livewire methods
they call exist (Task 7). Building the buttons before their target
methods exist would leave dead `wire:click` calls in the interim.

- [ ] **Step 2: Syntax-check the view**

Run: `/opt/homebrew/bin/php -l resources/views/livewire/team.blade.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Manual verification via the existing tests**

The `TeamTest` tests from Task 5 exercise this component's `render()`
path (Livewire renders the view on every `Livewire::test()` call), so a
broken view would already surface there. Run:

Run: `/opt/homebrew/bin/php vendor/bin/phpunit --filter=TeamTest`
Expected: PASS (all 7, unchanged from Task 5 — this task adds no new
test, it makes the existing ones' rendering path real instead of
exercising a view that didn't exist)

- [ ] **Step 4: Run the full suite**

Run: `/opt/homebrew/bin/php vendor/bin/phpunit`
Expected: PASS, 75 tests (unchanged count from Task 5).

- [ ] **Step 5: Commit**

```bash
git add resources/views/livewire/team.blade.php
git commit -m "feat: add the Team page view"
```

---

### Task 7: Role change, remove, leave, transfer ownership

**Files:**
- Modify: `src/Livewire/Team.php`
- Test: `tests/TeamTest.php`

**Interfaces:**
- Consumes: `MonitorUser::isOwner()` (Task 2), `Livewire\Team::actor()`
  (Task 5, `protected` — same class, directly callable).
- Produces: public methods on `Livewire\Team`:
  `changeRole(int $memberId, string $role): void` (owner only),
  `removeMember(int $memberId): void` (owner only, can't remove self),
  `leave(): void` (any member except a sole owner — redirects to
  `/monitor/login` after leaving, since the acting session is no longer
  valid), `transferOwnership(int $memberId): void` (owner only — target
  becomes `owner`, actor becomes `admin`).

- [ ] **Step 1: Write the failing tests**

Add to `tests/TeamTest.php`:

```php
public function test_owner_can_change_a_members_role(): void
{
    $viewer = MonitorUser::create([
        'name' => 'Viewer', 'email' => 'role-change-target@example.com',
        'password' => Hash::make('password'), 'role' => 'viewer',
    ]);

    Livewire::test(Team::class)->call('changeRole', $viewer->id, 'admin');

    $this->assertSame('admin', $viewer->fresh()->role);
}

public function test_admin_cannot_change_a_members_role(): void
{
    $admin = MonitorUser::create([
        'name' => 'Admin', 'email' => 'non-role-changing-admin@example.com',
        'password' => Hash::make('password'), 'role' => 'admin',
    ]);
    $viewer = MonitorUser::create([
        'name' => 'Viewer', 'email' => 'protected-role-target@example.com',
        'password' => Hash::make('password'), 'role' => 'viewer',
    ]);
    $this->actingAs($admin, 'monitor');

    Livewire::test(Team::class)->call('changeRole', $viewer->id, 'admin')->assertForbidden();

    $this->assertSame('viewer', $viewer->fresh()->role);
}

public function test_owner_can_remove_a_member(): void
{
    $viewer = MonitorUser::create([
        'name' => 'Viewer', 'email' => 'to-be-removed@example.com',
        'password' => Hash::make('password'), 'role' => 'viewer',
    ]);

    Livewire::test(Team::class)->call('removeMember', $viewer->id);

    $this->assertNull(MonitorUser::find($viewer->id));
}

public function test_owner_cannot_remove_themself(): void
{
    $owner = MonitorUser::where('email', 'owner@example.com')->firstOrFail();

    Livewire::test(Team::class)->call('removeMember', $owner->id)->assertForbidden();

    $this->assertNotNull(MonitorUser::find($owner->id));
}

public function test_admin_cannot_remove_a_member(): void
{
    $admin = MonitorUser::create([
        'name' => 'Admin', 'email' => 'non-removing-admin@example.com',
        'password' => Hash::make('password'), 'role' => 'admin',
    ]);
    $viewer = MonitorUser::create([
        'name' => 'Viewer', 'email' => 'protected-from-admin@example.com',
        'password' => Hash::make('password'), 'role' => 'viewer',
    ]);
    $this->actingAs($admin, 'monitor');

    Livewire::test(Team::class)->call('removeMember', $viewer->id)->assertForbidden();

    $this->assertNotNull(MonitorUser::find($viewer->id));
}

public function test_a_non_sole_owner_member_can_leave(): void
{
    $viewer = MonitorUser::create([
        'name' => 'Viewer', 'email' => 'leaving-viewer@example.com',
        'password' => Hash::make('password'), 'role' => 'viewer',
    ]);
    $this->actingAs($viewer, 'monitor');

    Livewire::test(Team::class)->call('leave')->assertRedirect('/monitor/login');

    $this->assertNull(MonitorUser::find($viewer->id));
}

public function test_the_sole_owner_cannot_leave(): void
{
    Livewire::test(Team::class)->call('leave');

    $owner = MonitorUser::where('email', 'owner@example.com')->first();
    $this->assertNotNull($owner, 'the sole owner must still exist — leave() must have been blocked');
}

public function test_owner_can_transfer_ownership_and_becomes_admin(): void
{
    $viewer = MonitorUser::create([
        'name' => 'Future Owner', 'email' => 'future-owner@example.com',
        'password' => Hash::make('password'), 'role' => 'viewer',
    ]);
    $originalOwner = MonitorUser::where('email', 'owner@example.com')->firstOrFail();

    Livewire::test(Team::class)->call('transferOwnership', $viewer->id);

    $this->assertSame('owner', $viewer->fresh()->role);
    $this->assertSame('admin', $originalOwner->fresh()->role);
}

public function test_admin_cannot_transfer_ownership(): void
{
    $admin = MonitorUser::create([
        'name' => 'Admin', 'email' => 'non-transferring-admin@example.com',
        'password' => Hash::make('password'), 'role' => 'admin',
    ]);
    $viewer = MonitorUser::create([
        'name' => 'Viewer', 'email' => 'not-getting-owner@example.com',
        'password' => Hash::make('password'), 'role' => 'viewer',
    ]);
    $this->actingAs($admin, 'monitor');

    Livewire::test(Team::class)->call('transferOwnership', $viewer->id)->assertForbidden();

    $this->assertSame('viewer', $viewer->fresh()->role);
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `/opt/homebrew/bin/php vendor/bin/phpunit --filter=TeamTest`
Expected: FAIL — `changeRole`/`removeMember`/`leave`/`transferOwnership`
don't exist yet (the 7 tests from Task 5/6 still pass; only the 9 new
ones fail).

- [ ] **Step 3: Add the four methods to `Livewire\Team`**

Add `use Illuminate\Support\Facades\Auth;` to the `use` block at the top
of `src/Livewire/Team.php`. Add these four public methods right after
`cancelInvite()`:

```php
    public function changeRole(int $memberId, string $role): void
    {
        $actor = $this->actor();

        if (! $actor->isOwner()) {
            abort(403);
        }

        if (! in_array($role, ['admin', 'viewer'], true)) {
            return;
        }

        $member = MonitorUser::query()->find($memberId);

        if ($member === null || $member->id === $actor->id) {
            return;
        }

        $member->update(['role' => $role]);
    }

    public function removeMember(int $memberId): void
    {
        $actor = $this->actor();

        if (! $actor->isOwner()) {
            abort(403);
        }

        if ($memberId === $actor->id) {
            abort(403);
        }

        MonitorUser::query()->find($memberId)?->delete();
    }

    public function leave(): void
    {
        $actor = $this->actor();

        if ($actor->isOwner() && MonitorUser::query()->where('role', 'owner')->count() <= 1) {
            $this->addError('leave', 'Transfer ownership to someone else before leaving — a team always needs an owner.');

            return;
        }

        $actor->delete();

        Auth::guard(MonitorUser::guardName())->logout();

        $this->redirectRoute('monitor.login');
    }

    public function transferOwnership(int $memberId): void
    {
        $actor = $this->actor();

        if (! $actor->isOwner()) {
            abort(403);
        }

        $newOwner = MonitorUser::query()->find($memberId);

        if ($newOwner === null || $newOwner->id === $actor->id) {
            return;
        }

        $newOwner->update(['role' => 'owner']);
        $actor->update(['role' => 'admin']);
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `/opt/homebrew/bin/php vendor/bin/phpunit --filter=TeamTest`
Expected: PASS (all 16 — 7 from Tasks 5/6 + 9 new)

- [ ] **Step 5: Run the full suite**

Run: `/opt/homebrew/bin/php vendor/bin/phpunit`
Expected: PASS, 84 tests (75 + 9 new).

- [ ] **Step 6: Commit**

```bash
git add src/Livewire/Team.php tests/TeamTest.php
git commit -m "feat: add role change, remove, leave, and transfer ownership"
```

---

### Task 8: Wire the remaining actions into `team.blade.php`

**Files:**
- Modify: `resources/views/livewire/team.blade.php`

**Interfaces:**
- Consumes: `changeRole()`/`removeMember()`/`leave()`/`transferOwnership()`
  (Task 7).

- [ ] **Step 1: Add per-row actions to the members list**

Replace the members-list `@foreach` block in
`resources/views/livewire/team.blade.php` (from Task 6) with:

```php
        <div class="mt-4 flex items-center gap-2 px-1 pb-3">
            <h3 class="font-semibold text-neutral-900 dark:text-neutral-100">{{ number_format($members->count()) }} {{ $members->count() === 1 ? 'Member' : 'Members' }}</h3>
        </div>
        <x-monitor::card class="p-4">
            <div class="divide-y divide-neutral-100 dark:divide-neutral-800">
                @foreach ($members as $member)
                    <div class="flex items-center gap-3 py-2.5">
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium text-neutral-900 dark:text-neutral-100">{{ $member->name }}</p>
                            <p class="truncate font-mono text-xs text-neutral-400 dark:text-neutral-500">{{ $member->email }}</p>
                        </div>
                        @if ($actor->isOwner() && $member->id !== $actor->id)
                            <select wire:change="changeRole({{ $member->id }}, $event.target.value)"
                                    class="shrink-0 rounded-md border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 px-1.5 py-1 font-mono text-[10px] uppercase tracking-tight text-neutral-500 dark:text-neutral-400">
                                <option value="admin" @selected($member->role === 'admin')>Admin</option>
                                <option value="viewer" @selected($member->role === 'viewer')>Viewer</option>
                            </select>
                            <button type="button" wire:click="transferOwnership({{ $member->id }})" wire:confirm="Make {{ $member->name }} the owner? You'll become an admin."
                                    class="shrink-0 rounded-md border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 px-2 py-1 font-mono text-[10px] uppercase tracking-tight text-neutral-500 dark:text-neutral-400 shadow-sm hover:bg-neutral-50 dark:hover:bg-neutral-800/50">Make owner</button>
                            <button type="button" wire:click="removeMember({{ $member->id }})" wire:confirm="Remove {{ $member->name }} from the team?"
                                    class="shrink-0 rounded-md border border-rose-200 dark:border-rose-500/30 bg-white dark:bg-neutral-900 px-2 py-1 font-mono text-[10px] uppercase tracking-tight text-rose-600 dark:text-rose-400 shadow-sm hover:bg-rose-50 dark:hover:bg-rose-500/10">Remove</button>
                        @else
                            <span class="shrink-0 rounded border px-1.5 py-0.5 font-mono text-[10px] uppercase tracking-tight {{ $roleBadge($member->role) }}">{{ $member->role }}</span>
                        @endif
                        @if ($member->id === $actor->id)
                            <button type="button" wire:click="leave" wire:confirm="Leave the team?"
                                    class="shrink-0 rounded-md border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 px-2 py-1 font-mono text-[10px] uppercase tracking-tight text-neutral-500 dark:text-neutral-400 shadow-sm hover:bg-neutral-50 dark:hover:bg-neutral-800/50">Leave</button>
                        @endif
                    </div>
                @endforeach
            </div>
            @error('leave')
                <p class="mt-3 text-sm text-rose-600 dark:text-rose-400">{{ $message }}</p>
            @enderror
        </x-monitor::card>
```

- [ ] **Step 2: Syntax-check the view**

Run: `/opt/homebrew/bin/php -l resources/views/livewire/team.blade.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Run the full suite**

Run: `/opt/homebrew/bin/php vendor/bin/phpunit`
Expected: PASS, 84 tests (unchanged — `TeamTest`'s `Livewire::test()`
calls already exercise this view's render path via the actions added in
Task 7, this task just makes the corresponding buttons real instead of
absent).

- [ ] **Step 4: Commit**

```bash
git add resources/views/livewire/team.blade.php
git commit -m "feat: wire role change, remove, leave, transfer ownership into the Team view"
```

---

### Task 9: Accept-invite flow

**Files:**
- Create: `src/Http/Controllers/Auth/InvitationController.php`
- Create: `resources/views/auth/accept-invitation.blade.php`
- Modify: `routes/web.php`
- Test: `tests/MonitorTest.php`

**Interfaces:**
- Consumes: `MonitorInvitation::findByPlainToken()` (Task 2),
  `MonitorUser::guardName()` (existing).
- Produces: routes `monitor.invitations.show`
  (`GET /monitor/invitations/{token}`), `monitor.invitations.store`
  (`POST /monitor/invitations/{token}`) — placed in `routes/web.php`
  alongside `monitor.setup`/`monitor.login` (reachable without being
  authenticated, still behind the outer `Authorize::class` Gate check).

- [ ] **Step 1: Write the failing tests**

Add to `tests/MonitorTest.php`:

```php
public function test_accept_invitation_page_is_shown_for_a_valid_token(): void
{
    Gate::define('viewMonitor', fn ($user = null) => true);
    $this->withoutMonitorAuth();

    $inviter = \LaravelMonitor\Models\MonitorUser::where('email', 'owner@example.com')->firstOrFail();
    ['plainToken' => $plainToken] = \LaravelMonitor\Models\MonitorInvitation::createFor('accept-page-test@example.com', 'viewer', $inviter);

    $this->get('/monitor/invitations/'.$plainToken)
        ->assertOk()
        ->assertSeeText('accept-page-test@example.com');
}

public function test_accept_invitation_returns_404_for_an_unknown_token(): void
{
    Gate::define('viewMonitor', fn ($user = null) => true);
    $this->withoutMonitorAuth();

    $this->get('/monitor/invitations/not-a-real-token')->assertNotFound();
}

public function test_accept_invitation_shows_an_expired_message_for_an_expired_token(): void
{
    Gate::define('viewMonitor', fn ($user = null) => true);
    $this->withoutMonitorAuth();

    $inviter = \LaravelMonitor\Models\MonitorUser::where('email', 'owner@example.com')->firstOrFail();
    ['invitation' => $invitation, 'plainToken' => $plainToken] = \LaravelMonitor\Models\MonitorInvitation::createFor('expired-test@example.com', 'viewer', $inviter);
    $invitation->forceFill(['expires_at' => now()->subHour()])->save();

    $this->get('/monitor/invitations/'.$plainToken)
        ->assertOk()
        ->assertSeeText('expired');
}

public function test_accepting_an_invitation_creates_the_user_with_the_invited_role_and_logs_them_in(): void
{
    Gate::define('viewMonitor', fn ($user = null) => true);
    $this->withoutMonitorAuth();

    $inviter = \LaravelMonitor\Models\MonitorUser::where('email', 'owner@example.com')->firstOrFail();
    ['invitation' => $invitation, 'plainToken' => $plainToken] = \LaravelMonitor\Models\MonitorInvitation::createFor('accepting@example.com', 'admin', $inviter);

    $this->post('/monitor/invitations/'.$plainToken, [
        'name' => 'Accepted Member',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ])->assertRedirect('/monitor');

    $user = \LaravelMonitor\Models\MonitorUser::where('email', 'accepting@example.com')->first();
    $this->assertNotNull($user);
    $this->assertSame('admin', $user->role);
    $this->assertTrue(\Illuminate\Support\Facades\Auth::guard('monitor')->check());
    $this->assertSame($user->id, \Illuminate\Support\Facades\Auth::guard('monitor')->id());
    $this->assertNull(\LaravelMonitor\Models\MonitorInvitation::find($invitation->id));
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `/opt/homebrew/bin/php vendor/bin/phpunit --filter=test_accept_invitation|test_accepting_an_invitation`
Expected: FAIL — routes don't exist yet.

- [ ] **Step 3: Write `InvitationController`**

```php
<?php

namespace LaravelMonitor\Http\Controllers\Auth;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use LaravelMonitor\Models\MonitorInvitation;
use LaravelMonitor\Models\MonitorUser;

class InvitationController
{
    public function show(string $token): View
    {
        $invitation = MonitorInvitation::findByPlainToken($token);

        abort_if($invitation === null, 404);

        return view('monitor::auth.accept-invitation', [
            'invitation' => $invitation,
            'token' => $token,
            'expired' => $invitation->isExpired(),
        ]);
    }

    public function store(Request $request, string $token): RedirectResponse
    {
        $invitation = MonitorInvitation::findByPlainToken($token);

        abort_if($invitation === null, 404);
        abort_if($invitation->isExpired(), 410);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = MonitorUser::create([
            'name' => $validated['name'],
            'email' => $invitation->email,
            'password' => Hash::make($validated['password']),
            'role' => $invitation->role,
        ]);

        $invitation->delete();

        Auth::guard(MonitorUser::guardName())->login($user);

        return redirect()->route('monitor.dashboard');
    }
}
```

- [ ] **Step 4: Write the accept-invitation view**

```php
{{-- Invite-acceptance page. See Http\Controllers\Auth\InvitationController. --}}
<x-monitor::layout title="Accept invitation">
    <div class="flex min-h-screen items-center justify-center bg-neutral-50 px-4 dark:bg-neutral-950">
        <div class="w-full max-w-sm">
            <div class="mb-6 flex items-center justify-center gap-2.5">
                <span class="flex h-9 w-9 items-center justify-center rounded-md bg-neutral-900 text-sm font-semibold text-white dark:bg-neutral-700">{{ strtoupper(mb_substr(config('app.name', 'L'), 0, 1)) }}</span>
                <span class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">{{ config('app.name', 'Laravel') }} Monitor</span>
            </div>

            <div class="rounded-lg border border-neutral-200 bg-white p-6 shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                @if ($expired)
                    <h1 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">This invitation has expired</h1>
                    <p class="mt-1 text-sm text-neutral-500 dark:text-neutral-400">Ask an owner or admin to invite {{ $invitation->email }} again.</p>
                @else
                    <h1 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">Join {{ config('app.name', 'Laravel') }} Monitor</h1>
                    <p class="mt-1 text-sm text-neutral-500 dark:text-neutral-400">Create your account for <strong>{{ $invitation->email }}</strong> as {{ $invitation->role }}.</p>

                    @if ($errors->any())
                        <div class="mt-4 rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-600 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-400">
                            {{ $errors->first() }}
                        </div>
                    @endif

                    <form method="POST" action="{{ route('monitor.invitations.store', $token) }}" class="mt-4 space-y-4">
                        @csrf
                        <div>
                            <label for="name" class="block font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400">Name</label>
                            <input type="text" name="name" id="name" value="{{ old('name') }}" required autofocus
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
                @endif
            </div>
        </div>
    </div>
</x-monitor::layout>
```

Note: the form posts to `$token` (the plain token from the URL, passed
through in Step 3), not `$invitation->token` — the stored `token` column
is a SHA-256 hash and can't be used to look the invitation back up.

- [ ] **Step 5: Register the routes**

In `routes/web.php`, add
`use LaravelMonitor\Http\Controllers\Auth\InvitationController;` to the
`use` block, and add these two lines right after the existing
`monitor.logout` line, still inside the outer group but before the inner
`EnsureMonitorAuthenticated`-gated group:

```php
        Route::get('/invitations/{token}', [InvitationController::class, 'show'])->name('monitor.invitations.show');
        Route::post('/invitations/{token}', [InvitationController::class, 'store'])->name('monitor.invitations.store');
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `/opt/homebrew/bin/php vendor/bin/phpunit --filter=test_accept_invitation|test_accepting_an_invitation`
Expected: PASS (all 4)

- [ ] **Step 7: Run the full suite**

Run: `/opt/homebrew/bin/php vendor/bin/phpunit`
Expected: PASS, 88 tests (84 + 4 new).

- [ ] **Step 8: Commit**

```bash
git add src/Http/Controllers/Auth/InvitationController.php resources/views/auth/accept-invitation.blade.php routes/web.php tests/MonitorTest.php
git commit -m "feat: add the invitation accept flow"
```

---

### Task 10: Final verification

**Files:** none (verification only)

- [ ] **Step 1: Run the full suite one more time from a clean state**

Run: `/opt/homebrew/bin/php vendor/bin/phpunit`
Expected: PASS, 88 tests, 0 failures, pristine output.

- [ ] **Step 2: Syntax-check every new/modified Blade view**

Run:
```bash
/opt/homebrew/bin/php -l resources/views/livewire/team.blade.php
/opt/homebrew/bin/php -l resources/views/auth/accept-invitation.blade.php
/opt/homebrew/bin/php -l resources/views/components/header.blade.php
/opt/homebrew/bin/php -l resources/views/mail/invitation.blade.php
```
Expected: `No syntax errors detected` for all four.

- [ ] **Step 3: Manual smoke check (optional but recommended)**

If a local consuming app is available, migrate the new
`monitor_invitations` table and click through by hand: visit the Team
tab as the owner, invite a viewer, check the invite email (via the log
mail driver or Mailtrap-equivalent, depending on the local app's mail
config), open the accept link, create the account, confirm the new
viewer can't invite/remove/change roles, confirm the owner can promote
them to admin, confirm the sole-owner-leave block and transfer-ownership
both work.

- [ ] **Step 4: Report status**

This closes sub-project 2/4 (Team & Members). Report the final test
count and ask whether to push/open a PR, or continue straight to
sub-project 3 (Forgot password).
