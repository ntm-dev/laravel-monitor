# Additional login methods — TOTP, Passkey, OAuth (sub-project 4 of 4)

Date: 2026-07-20
Status: Approved (pending implementation plan)

## Motivation

Sub-projects 1-3 gave `laravel-monitor` its own login system: a dedicated
`monitor` guard, first-run owner setup, email+password login, team
invites/roles, self-service password reset, and admin-approved
email-change. Every one of those still assumes password is the only
credential. The original decomposition (sub-project 1's spec) deferred
three independent login methods to this final sub-project: TOTP
authenticator apps, Passkey (WebAuthn), and OAuth (Google/Apple). All
three are built here, in that order — TOTP first (smallest surface, no
external ceremony), then Passkey, then OAuth (most external
integration).

## Decisions made during brainstorming

- **TOTP is 2FA, not a replacement for password.** A user who enables it
  still logs in with email+password first; a correct TOTP code (or a
  recovery code) is a second, required step. This matches the industry
  norm (Fortify, GitHub, Google) and keeps password meaningful once TOTP
  is on, unlike a passwordless "email + code only" design.
- **Passkey and OAuth are both standalone passwordless logins**, not
  second factors — a successful WebAuthn ceremony or OAuth callback logs
  the user in directly, skipping the TOTP challenge even if the target
  user has TOTP enabled. Both already combine "something you have"
  (device / provider session) with "something you are or know"
  (biometric/PIN, or the provider's own login), so stacking a TOTP prompt
  on top would add friction without adding real security.
- **OAuth never creates a `MonitorUser`.** It only authenticates an
  existing row, matched by the provider's verified email. This preserves
  the invite-only membership model from sub-project 2 — there is no path
  to dashboard access other than being invited by an owner/admin.
- **All new composer dependencies are optional (`suggest`, not
  `require`)**, mirroring Laravel's own driver pattern (e.g.
  `league/flysystem-aws-s3-v3` for the `s3` filesystem disk). Nothing in
  this sub-project forces every installation to pull in WebAuthn/OAuth
  libraries it will never use.
- **Feature UI degrades visibly, not silently**, when its library isn't
  installed: the relevant card/button still renders, but disabled, with a
  one-line note naming the `composer require` command that enables it.
  An owner browsing Settings/Team should discover these features exist
  even before installing anything.
- **Library choices**: `pragmarx/google2fa` + `bacon/bacon-qr-code` for
  TOTP (both pure PHP, no heavy transitive dependencies — fits this
  package's already-minimal footprint); `web-auth/webauthn-lib` for
  Passkey (heavier than the minimal-footprint alternative
  `lbuchs/webauthn`, but standards-compliant and the most widely used PHP
  WebAuthn implementation — chosen deliberately over the lighter option);
  `laravel/socialite` + `socialiteproviders/apple` for OAuth (Socialite
  ships Google support directly; Apple needs the community provider since
  Socialite's core doesn't include it).

## Architecture shared across all three methods

### Partial-authentication state for TOTP's second step

`LoginController::store()` currently calls
`Auth::guard('monitor')->attempt($credentials)` and, on success,
regenerates the session and redirects straight to the dashboard. Once
TOTP exists, a successful password check for a user with
`totp_enabled_at` set must NOT complete login yet. Instead:

1. Password check succeeds → store `$user->id` under a dedicated session
   key (`monitor_2fa_challenge_user_id`), not the guard's own session
   state, and redirect to `GET /monitor/two-factor-challenge`.
2. That page accepts a 6-digit TOTP code or a recovery code.
3. Only on a correct code does the controller call
   `Auth::guard('monitor')->login($user)` and `session()->regenerate()`.

Until step 3, `Auth::guard('monitor')->guest()` is still `true`, so
`Authorize`/`EnsureMonitorAuthenticated` middleware needs no changes —
the existing guest-redirect behavior already covers a visitor mid-challenge.
A user without TOTP enabled skips straight to step 3 as today, unchanged.

### Optional dependencies

A single helper, `LaravelMonitor\Support\OptionalAuthMethod`, centralizes
availability checks so controllers, middleware, and Blade views all read
from one source of truth instead of scattering `class_exists()` calls:

- `OptionalAuthMethod::totpAvailable(): bool` —
  `class_exists(\PragmaRX\Google2FA\Google2FA::class)`
- `OptionalAuthMethod::passkeysAvailable(): bool` —
  `class_exists(\Webauthn\PublicKeyCredentialCreationOptions::class)`
- `OptionalAuthMethod::oauthAvailable(string $provider): bool` — checks
  both the relevant Socialite class and that the provider's config keys
  (client id/secret) are non-empty, so a library-present-but-unconfigured
  provider also degrades to disabled+note rather than erroring at
  redirect time.

Routes for all three methods are registered unconditionally in
`routes/web.php` (conditionally omitting routes based on a runtime
`class_exists()` check would silently break under `route:cache`).
Each controller action checks the relevant `OptionalAuthMethod::*` at the
top and `abort(404)` if unavailable — indistinguishable from the route
not existing, since reaching these directly requires bypassing UI that
already hides/disables the entry point.

### New schema — single migration file

Per this repo's migration convention, everything below is added to the
existing `database/migrations/2026_01_01_000000_create_monitor_table.php`
— no new migration file.

- `monitor_users` gains three nullable columns: `totp_secret` (uses
  Eloquent's `encrypted` cast — this one needs to be *decrypted* to
  generate/verify codes, unlike every other token in this package, which
  is hashed one-way since it's only ever compared, never read back),
  `totp_enabled_at` (timestamp), `totp_recovery_codes` (JSON array of
  `Hash::make()`'d codes).
- New table `monitor_webauthn_credentials`: `id`, `user_id`,
  `credential_id` (unique), `public_key`, `label`, `sign_count`,
  `created_at`.
- New table `monitor_oauth_accounts`: `id`, `user_id`, `provider`
  (`google|apple`), `provider_user_id`, timestamps; unique on
  `(provider, provider_user_id)`.

Table/column names are configurable via `config/monitor.php` under
`auth.*`, matching the existing pattern for `invitations_table` etc.

## Scope: TOTP

- Team page gains a "Two-factor authentication" card (in the existing
  self-service area, next to "Change your email"). Disabled+note if
  `OptionalAuthMethod::totpAvailable()` is false.
- Enable flow: generates a secret, shows a QR code (SVG via
  `bacon/bacon-qr-code`, encoding the standard `otpauth://` URI) and the
  secret as plain text underneath. User must enter one valid code before
  it's persisted (`totp_secret`, `totp_enabled_at`) — this catches a
  mistyped/misscanned secret before it locks the user in. On successful
  enable, 8 recovery codes are generated and shown exactly once (stored
  hashed; there is no "view again" — losing them means relying on the
  admin-override path below).
- Login challenge (`GET/POST /monitor/two-factor-challenge`): accepts a
  6-digit code (verified via `pragmarx/google2fa`, allowing the standard
  ±1 time-step window) or a recovery code (verified via `Hash::check`
  against each stored hash). A correct recovery code both logs the user
  in and removes that code from the stored array (single use).
- Disable flow (self-service): requires re-entering the current password
  before clearing `totp_secret`/`totp_enabled_at`/`totp_recovery_codes` —
  it's a security-reducing action.
- Admin override: on the Team page's member list, an owner sees a
  "Disable 2FA" action next to any member who has `totp_enabled_at` set
  (same placement/style as the existing Remove/Change role actions) —
  the escape hatch for someone locked out with no recovery codes left.
  Only the owner, not admins, gets this (matches the existing pattern
  where the most destructive member actions are owner-only).

## Scope: Passkey (WebAuthn)

- Same Team page area gains a "Passkeys" card: lists the user's own
  registered credentials (label, created date, a "Remove" action per
  row) and an "Add a passkey" button. Disabled+note if
  `OptionalAuthMethod::passkeysAvailable()` is false.
- Registration: `POST /monitor/webauthn/register/options` returns a
  `PublicKeyCredentialCreationOptions` payload (challenge stored in
  session, short-lived); client-side JS calls
  `navigator.credentials.create()`; the result posts to
  `POST /monitor/webauthn/register` for verification and storage as a
  new `monitor_webauthn_credentials` row. Multiple passkeys per user are
  expected (one per device) — there is no cap.
- Login: login page gets a "Sign in with a passkey" button (disabled+note
  if unavailable), usernameless — `navigator.credentials.get()` is called
  without a prior email step, `POST /monitor/webauthn/authenticate`
  resolves the returned `credential_id` back to its `monitor_users` row
  and logs that user in directly, bypassing the TOTP challenge per the
  brainstorming decision above.

## Scope: OAuth (Google + Apple)

- Login page gets "Continue with Google" and "Continue with Apple"
  buttons, each independently disabled+noted based on
  `OptionalAuthMethod::oauthAvailable('google'|'apple')`.
- `GET /monitor/oauth/{provider}/redirect` — Socialite's standard
  redirect. `GET /monitor/oauth/{provider}/callback` — reads the
  provider's verified email; if no `MonitorUser` has that email, shows a
  clear "No dashboard account uses this email — ask an owner/admin to
  invite you" error (no account is created). If a match exists, upserts
  a `monitor_oauth_accounts` row (`provider`, `provider_user_id`,
  `user_id`) and logs that user in directly (bypasses TOTP, same
  reasoning as Passkey).
- New config under `monitor.auth.oauth`: `google.client_id`,
  `google.client_secret`, `apple.client_id`, `apple.client_secret`,
  `apple.key`, `apple.team_id` — all `env()`-backed, following the
  existing `config/monitor.php` convention.

## Out of scope

- Requiring/enforcing any of these methods team-wide (all three are
  opt-in per user; there is no "owner mandates 2FA for everyone" control).
- Any OAuth provider beyond Google and Apple.
- Passkey/OAuth as a *second* factor stacked on top of password or each
  other — each of the three methods here is a standalone, complete way to
  authenticate.

## Known risks

Registering a passkey or enabling TOTP from an already-authenticated
session requires no re-authentication step (no current-password prompt),
so a compromised session can add a persistent, password-independent
credential that survives a subsequent password reset. This matches the
trust model this package already uses elsewhere — the Team page's
invite and email-change flows also let any authenticated session take
security-relevant actions without re-auth. Flagged during final review
as an accepted risk for this release rather than an oversight: it is not
addressed here by a step-up re-auth flow, which would need to be a
deliberate, separately-scoped addition if the trust model changes later.

## Testing plan

- **TOTP**: enabling with a correct confirmation code persists the
  secret and 8 hashed recovery codes; enabling with a wrong confirmation
  code persists nothing; login for a TOTP-enabled user redirects to the
  challenge instead of the dashboard and does not establish a guard
  session; a correct code on the challenge logs in; a wrong code does
  not; a recovery code logs in and is removed from the stored array
  (reusing it afterward fails); self-disable requires the correct current
  password; owner can disable another member's 2FA, admin/viewer get 403.
- **Passkey**: registration round-trip persists a `monitor_webauthn_credentials`
  row tied to the registering user (verified via the library's own
  test/ceremony-mocking support, not a real browser); authentication
  round-trip with a previously registered credential logs in the correct
  user without any password/TOTP step; removing a passkey deletes only
  that row and only for its owner.
- **OAuth**: `Socialite::shouldReceive(...)` mocked per provider —
  callback with an email matching an existing `MonitorUser` logs that
  user in and creates/updates the `monitor_oauth_accounts` row; callback
  with an unmatched email shows the error and creates no `MonitorUser`
  and no orphaned `monitor_oauth_accounts` row; a second login through
  the same provider reuses the existing linked row rather than duplicating it.
- **Optional-dependency UI**: `OptionalAuthMethod::totpAvailable()` /
  `passkeysAvailable()` / `oauthAvailable()` are unit-tested directly
  against `class_exists()`/config state rather than by actually
  uninstalling composer packages in CI; a thin view-level test confirms
  each card/button renders in its disabled+note state when the
  corresponding `OptionalAuthMethod` check returns false.
- Full existing suite continues to pass unchanged (methods without TOTP
  enabled must see zero behavior change in the existing login test coverage).
