# Forgot password & email-change approval (sub-project 3 of 4)

Date: 2026-07-20
Status: Approved (pending implementation plan)

## Motivation

Sub-projects 1 (Foundation) and 2 (Team & Members) gave `laravel-monitor`
its own login system and member management, but two gaps remain from the
original request: a locked-out user has no self-service way back in
(no "forgot password"), and there's no way for a member to change their
own email address — a real need once accounts are created by invite
rather than self-registration, and email addresses change. Both were
explicitly deferred here by sub-project 2's spec.

This sub-project ships both, since they're closely related (both are
"prove you control an inbox, then something happens to your account")
and both were already promised to land together in the original
four-way decomposition.

## Scope

In scope:
- Self-service password reset via emailed link.
- Self-service email-change request, gated behind new-email verification
  and (except for the owner) admin/owner approval.
- A "Forgot password?" link on the login page (intentionally left off
  until now, per sub-project 1's spec, to avoid shipping a dead link).
- A "Pending email changes" section on the Team page, alongside the
  existing "Pending invitations" section.

Out of scope (unchanged from the original decomposition):
- OAuth/passkey/TOTP login methods (sub-project 4).
- Any "log out other sessions" / session-invalidation work beyond what
  Laravel's guard already does for free (see sub-project 2's spec, which
  covers why role/removal changes take effect on the next request with
  no extra work — the same reasoning applies here: a password reset or
  email change doesn't need to touch other sessions, since neither
  action is a security-incident-response feature in this scope).

## Data model

Two new tables, both following the `monitor_invitations` pattern from
sub-project 2 (plain token only ever appears in the emailed link; the
stored column is a SHA-256 hash so a DB read alone can't be used to
forge a reset/verification):

**`monitor_password_resets`** (table name configurable via
`monitor.auth.password_resets_table`):
- `email` — not unique-constrained at the DB level (a table can only
  hold one live token per email in practice, via `updateOrCreate`, but
  the column itself doesn't need a unique index for that).
- `token` — hashed, as above.
- `created_at`.

No `MonitorPasswordReset` model needed as an Eloquent model with
relationships — it's a flat lookup table, same shape as Laravel's own
default `password_reset_tokens`. Implemented as a small Eloquent model
(`LaravelMonitor\Models\MonitorPasswordReset`) purely for `createFor()`
/ `findByPlainToken()` / expiry-check helpers, mirroring
`MonitorInvitation`'s API so the controller code reads the same way.

**`monitor_email_changes`** (`monitor.auth.email_changes_table`):
- `user_id` — the `monitor_users.id` of whoever requested the change (no
  DB foreign key, matching the package's existing convention for
  `invited_by`).
- `new_email`.
- `token` — hashed.
- `verified_at` — nullable. Null means "link not yet clicked"; such rows
  are invisible to the Team page's approval UI regardless of role, so an
  admin/owner never has to make a decision about an email nobody has
  proven they control yet.
- `expires_at`.
- timestamps.

`LaravelMonitor\Models\MonitorEmailChange` mirrors `MonitorInvitation`'s
API: `createFor(MonitorUser $requester, string $newEmail)`,
`findByPlainToken()`, `isExpired()`, plus `isVerified(): bool`
(`verified_at !== null`).

Both tokens expire **60 minutes** after creation (`MonitorInvitation`'s
2-hour window doesn't apply here — these tokens gate account-security
actions, not a one-time onboarding step, so a tighter window is the
safer default; matches Laravel's own out-of-the-box password-reset
expiry).

## Flow 1: Forgot password

1. `login.blade.php` gains a "Forgot password?" link to
   `GET /monitor/forgot-password` (`PasswordResetController::show`) — a
   plain email-address form, same visual pattern as
   `login.blade.php`/`setup.blade.php`.
2. `POST /monitor/forgot-password` (`PasswordResetController::create`):
   looks up the email in `monitor_users`; if found, creates/refreshes a
   `MonitorPasswordReset` row (`updateOrCreate` by email, same
   re-request-refreshes-the-token behavior as invitations) and emails a
   `PasswordResetMail` containing the link. **Always shows the same
   "if that email has an account, we've sent a reset link" response**,
   whether or not the email exists — unlike `Team::invite()` (where
   revealing "this email is already a member" is fine, since that's an
   authenticated admin managing their own team), this endpoint is
   reachable by anyone unauthenticated, so it must not let a visitor
   enumerate which emails have accounts.
3. `GET /monitor/reset-password/{token}` (`PasswordResetController::edit`):
   404 if the token is unknown or expired (no separate "expired" message
   page here, unlike invitations — a stale reset link is expected to be
   thrown away and re-requested, there's no "ask an owner to resend it"
   step to point the user at). Shows a new-password + confirmation form.
4. `POST /monitor/reset-password/{token}`
   (`PasswordResetController::update`): validates the new password,
   **deletes the `monitor_password_resets` row first** as an atomic
   claim (same fix just applied to `InvitationController::store` for
   the identical double-submit race — a losing concurrent request sees
   zero rows affected and gets a clean 404 instead of a 500 on some
   downstream constraint), then updates the user's password, logs them
   in on the `monitor` guard, and redirects to the dashboard.

Both routes sit in `routes/web.php` alongside `/setup`, `/login`, and
`/invitations/{token}` — inside the outer `Authorize::class`-gated
group, outside `EnsureMonitorAuthenticated` (a locked-out visitor is by
definition not authenticated yet).

## Flow 2: Email change (verify, then approve)

1. On the Team page, every role gets a "Change email" action for
   *themselves only* (no changing anyone else's email — that's not a
   thing this sub-project introduces). Entering a new email calls
   `Team::requestEmailChange(string $newEmail)`, which:
   - Rejects (form error, same style as `invite()`'s "already a member"
     check) if `$newEmail` fails `filter_var(..., FILTER_VALIDATE_EMAIL)`
     or is already taken by an existing `monitor_users` row.
   - Creates a `MonitorEmailChange` row for the actor (`updateOrCreate`
     by `user_id`, so requesting again before verifying replaces the
     pending one rather than stacking duplicates) and emails
     `EmailChangeVerificationMail` to the **new** address — not the
     user's current one, since the entire point is proving they control
     the new inbox.
2. `GET /monitor/email-changes/{token}`
   (`EmailChangeController::verify`) — reachable unauthenticated (same
   placement as the invitation/password-reset routes), since the person
   clicking it is proving inbox ownership, not proving they're logged in
   on that device. 404 if the token is unknown or expired. On a valid
   token:
   - Sets `verified_at = now()`.
   - **If the requesting user is the owner**: applies the change
     immediately — updates `monitor_users.email`, deletes the
     `monitor_email_changes` row — and shows a plain "your email has
     been updated" confirmation page. No approval step exists for the
     owner, since nobody outranks them to approve it, and blocking an
     owner on their own inbox-verified request would just be friction
     with no security benefit (an owner who already controls the new
     inbox can just as easily assign themselves the requested email any
     other way — there's no one to protect the action from).
   - **Otherwise** (admin or viewer requester): leaves the row as
     verified-but-pending and shows a "verified — waiting for an
     owner/admin to approve" confirmation page instead.
3. The Team page gains a "Pending email changes" section (verified rows
   only), each showing the requester and their requested new email, with
   Approve/Reject actions gated by the requester's *current* role:
   - Requester is `admin` → only the **owner** sees Approve/Reject
     (`Team::approveEmailChange()`/`rejectEmailChange()` `abort(403)`
     unless `actor->isOwner()`).
   - Requester is `viewer` → owner **or** admin sees Approve/Reject
     (`actor->canManageTeam()`).
   - Self-approval never arises structurally: an admin's request needs
     an owner (a different person, since the admin isn't one), and a
     viewer's request needs `canManageTeam()`, which a viewer never has.
     No explicit `$actor->id === $requester->id` guard is needed the way
     `changeRole()`/`removeMember()` need one in sub-project 2 — those
     let a peer act on a peer; here the approver tier is always strictly
     above the requester tier by construction.
   - **Approve**: re-checks the new email is still free (a second user
     could have claimed it in the meantime), updates
     `monitor_users.email`, deletes the row.
   - **Reject**: deletes the row. No notification back to the requester
     (matches `cancelInvite()` — no notification there either, this
     package doesn't build a notification system in this scope).

## Testing plan

- `monitor_password_resets` / `monitor_email_changes` migrations:
  tables/columns exist, tokens stored hashed.
- Forgot password: known email creates/refreshes a token and sends
  `PasswordResetMail`; unknown email still returns the generic success
  response and sends nothing (`Mail::assertNothingSent()`); the response
  body is identical in both cases (no enumeration signal).
- Reset password: valid unexpired token shows the form and updates the
  password on submit, logging the user in; expired or unknown token
  404s; a second submit of the same token after a successful first one
  404s instead of erroring (race-safety, same pattern as the
  invitation-accept fix); old password stops working, new one works.
- Request email change: any role can request for themselves; invalid
  email format rejected with a form error and no row/mail created;
  requesting an email already taken by another member rejected the same
  way; requesting again before verifying replaces the pending request
  rather than creating a second one; `EmailChangeVerificationMail` goes
  to the **new** address, not the requester's current one.
- Verify: valid unexpired token sets `verified_at`; unknown/expired
  token 404s; owner's request applies immediately on verification
  (`monitor_users.email` updated, row deleted); non-owner's request
  stays pending after verification (email unchanged, row still present
  with `verified_at` set).
- Team page visibility: an unverified email-change row never appears in
  "Pending email changes" regardless of viewer's role.
- Approve/reject permissions: admin's pending request — owner can
  approve/reject, admin (any admin, including a different one) gets 403
  on both; viewer's pending request — owner and admin can both
  approve/reject, another viewer gets 403 on both. Approving updates the
  email and removes the row; rejecting removes the row without changing
  the email. Approving a request whose target email was claimed by
  someone else in the meantime fails cleanly (validation error, not a
  DB-constraint 500) and leaves the pending row in place for the admin
  to re-decide.
