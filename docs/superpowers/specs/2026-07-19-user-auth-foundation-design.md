# User & team system — Foundation (sub-project 1 of 4)

Date: 2026-07-19
Status: Approved (pending implementation plan)

## Motivation

The user wants `laravel-monitor` to grow a full user/team system: invite
members by email, three roles (owner/admin/viewer), self-service password
reset, an admin-approved email-change flow, and multiple login methods
(password, OAuth, passkey, TOTP authenticator). This is a genuinely large
scope — comparable to bundling Fortify + Jetstream + Socialite + WebAuthn
into what is today a zero-auth, "defer entirely to the host app" package
(the dashboard is currently gated by a single `viewMonitor` Gate checked
against the host Laravel app's own `Auth::user()`).

This is too large for one spec. It was decomposed into four sub-projects,
built in order:

1. **Foundation** (this spec) — the `monitor_users` table, password login,
   the package's own auth guard, and the first-run "create the owner"
   flow. Nothing else works without this.
2. **Team & Members** — invite by email, roles, accept invite, leave,
   transfer ownership, and the admin UI to approve/reject a pending
   email-change request (same page as the member list).
3. **Forgot password** — email a reset link.
4. **Additional login methods** (independent of each other, can be built
   in parallel once Foundation exists) — OAuth (Google/iCloud), Passkey
   (WebAuthn), TOTP authenticator app.

This spec covers **Foundation only**. Sub-projects 2-4 get their own
spec/plan cycle once this one ships.

## Decisions made during brainstorming

- **Scope confirmed**: this genuinely lives inside `laravel-monitor`
  itself, not a different app. It's a deliberate departure from the
  package's current "no identity of its own" design.
- **Single team**: exactly one team per installation. No `teams` table —
  a flat `monitor_users` table with a `role` column is enough.
- **Relationship to the existing `viewMonitor` Gate**: NOT an alternate
  entry path. The Gate is a global on/off switch the host app already
  defines — if it returns `false`, the dashboard is unreachable for
  everyone, full stop. The new per-user login system is an *additional*
  layer that runs *inside* that switch: passing the Gate is necessary but
  no longer sufficient — the visitor must also be authenticated against
  the package's own guard. `Authorize` middleware keeps its existing
  `Gate::allows('viewMonitor', [$request->user()])` check unchanged and
  gains a second check after it.
- **Dedicated auth guard**: a new Laravel guard named `monitor`
  (session-based), registered by `MonitorServiceProvider`. Laravel
  isolates session state per guard name automatically (distinct
  `login_<guard>_<hash>` session key) — no separate cookie or session
  driver needed, and it cannot collide with the host app's own
  `Auth::user()` / default guard.
- **Naming-collision review** (see table below) — the only real residual
  risk is the `monitor_users` table name, so it's configurable like
  `monitor_entries`/`monitor_issues` already are.

| Component | Name | Collision risk |
|---|---|---|
| DB table | `monitor_users` (configurable) | Low — same pattern as existing configurable table names |
| Route names | `monitor.login`, `monitor.setup`, `monitor.logout` | None — same `monitor.*` namespace already used everywhere |
| URL paths | `/monitor/setup`, `/monitor/login` | None — under the existing configurable `monitor.path` prefix |
| Model class | `LaravelMonitor\Models\MonitorUser` | None — distinct namespace from the host app's own User model |
| Auth guard | `monitor` | Very low — only if the host app happens to name one of its own guards `monitor` |
| Session key | auto-derived from guard name | None — Laravel isolates per guard automatically |
| Blade view namespace | `monitor::auth.*` | None — same `monitor::` namespace already used for every view |

## Scope

In scope:
- `monitor_users` table: `id`, `name`, `email` (unique), `password`,
  `role` (`owner|admin|viewer`), timestamps.
- `LaravelMonitor\Models\MonitorUser` — Eloquent model implementing
  `Illuminate\Contracts\Auth\Authenticatable` (or extending
  `Illuminate\Foundation\Auth\User`), mapped to the configurable table.
- A new `monitor` auth guard + `eloquent` provider pointed at
  `MonitorUser`, registered by `MonitorServiceProvider` (config merge at
  boot, no `config/auth.php` publish needed).
- `Authorize` middleware gains a second check after the existing Gate
  check: if `Auth::guard('monitor')->guest()`, redirect to `/monitor/setup`
  (when `monitor_users` is empty) or `/monitor/login` (otherwise) instead
  of aborting.
- Routes: `GET/POST /monitor/setup`, `GET/POST /monitor/login`,
  `POST /monitor/logout` — registered in the same route group as the rest
  of the package (same domain/prefix config) but NOT behind the new
  auth-check (a visitor obviously isn't authenticated yet when reaching
  these).
- `Http/Controllers/Auth/SetupController.php` — `show()`/`store()`.
  `store()` only succeeds while `monitor_users` is empty (guards against
  someone re-hitting `/monitor/setup` after a user already exists); the
  first created row gets `role = 'owner'`.
- `Http/Controllers/Auth/LoginController.php` — `show()`/`store()`/`destroy()`
  (login form, login submit, logout).
- Views: `resources/views/auth/setup.blade.php`,
  `resources/views/auth/login.blade.php` — plain `<x-monitor::layout>`
  wrapping (no sidebar, matching the pattern of not-yet-authenticated
  pages), styled consistently with the rest of the dashboard.
- Role enforcement, proven end-to-end on real functionality rather than
  left inert: a `MonitorUser::canManageSettings(): bool` **instance**
  method (checked as `$request->user('monitor')->canManageSettings()`)
  (`true` for `owner`/`admin`) applied to the two existing
  `POST /monitor/settings/system` and `POST /monitor/settings/reset`
  routes — a `viewer` gets `403`.

Out of scope (deferred to sub-projects 2-4):
- Inviting members, accepting invites, changing another user's role,
  leaving, transferring ownership.
- Forgot-password flow (the login page does not get a "forgot password"
  link yet — added together with its functionality in sub-project 3, to
  avoid shipping a dead link).
- Email-change request + admin approval workflow.
- OAuth/passkey/TOTP login methods — the login page only offers
  email+password in this sub-project.
- Any member-list / user-management UI (the Settings page gains a
  permission check, but not a "Users" tab — that's sub-project 2).

## Impact on the existing test suite

`Authorize` is applied to the *entire* route group in `routes/web.php`
(dashboard, every detail page, settings — all of it), not just the two
settings-mutation routes. Once it also requires a `monitor` guard session,
every one of the ~91 existing tests that currently does
`Gate::define('viewMonitor', fn () => true); $this->get('/monitor/...')`
would start failing, since none of them log in through the new guard —
and none of them are testing the auth system itself, so rewriting each
one individually would be pure churn for a cross-cutting, orthogonal
concern.

Fix: `tests/TestCase.php::setUp()` seeds one `monitor_users` row
(`role = 'owner'`) and logs it in via `$this->actingAs($user, 'monitor')`
by default, alongside its existing per-test setup (Monitor buffer
flush/storage purge). Existing tests keep working completely unchanged.
Tests that need to exercise an unauthenticated state, a specific
non-owner role, or the setup/login flows themselves opt out explicitly
(e.g. a `withoutMonitorAuth()` helper that logs the guard out before the
request, or constructing their own `MonitorUser` with a specific role and
calling `actingAs($user, 'monitor')` again to override the default).

## Testing plan

- Migration: table/columns exist with expected types and the unique
  constraint on `email`.
- `SetupController`: first visit with an empty `monitor_users` table
  shows the form (this test must opt out of `TestCase`'s default
  auto-login/seed, since the whole point is exercising the zero-users
  state); submitting creates a `role = 'owner'` row and logs the new user
  in; a second visit once a user exists is rejected (redirects to
  `/monitor/login` instead, matching `Authorize`'s own redirect rule).
- `LoginController`: correct credentials log in and redirect to the
  dashboard; wrong password/unknown email re-shows the form with a
  validation error and does not authenticate; logout clears the guard's
  session and a subsequent request redirects to login again (these also
  opt out of the default auto-login, to start from a logged-out state).
- `Authorize` middleware: unauthenticated + empty table → redirects to
  setup; unauthenticated + existing users → redirects to login;
  authenticated → passes through unchanged; `Gate::allows('viewMonitor')`
  returning `false` still hard-aborts regardless of auth state (existing
  behavior, must not regress).
- Role enforcement: a `viewer`-role authenticated user (override the
  `TestCase` default owner via `actingAs($viewer, 'monitor')`) gets `403`
  on both settings POST routes; the default `owner` fixture (already
  logged in by `TestCase::setUp()`) succeeds, so the existing settings
  tests double as the "owner can" half of this coverage for free.
- The full existing suite (91 tests) still passes unchanged after this
  lands, proving the auto-login fixture is transparent to everything that
  isn't specifically testing auth.
