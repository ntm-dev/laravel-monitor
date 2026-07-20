# Team & Members (sub-project 2 of 4)

Date: 2026-07-20
Status: Approved (pending implementation plan)

## Motivation

Sub-project 1 (Foundation, shipped on this branch) gave `laravel-monitor` its
own login system — a `monitor_users` table, a dedicated `monitor` auth
guard, first-run owner setup, and role enforcement proven on the Settings
routes. It intentionally stopped there: there is still no way for the
owner to add anyone else. This sub-project closes that gap — inviting
members by email, accepting an invite, changing roles, removing a member,
leaving, and transferring ownership.

Two items from the original decomposition were reallocated during
brainstorming, both confirmed with the user:
- The new member-management page is named **"Team"**, not "Users" — the
  dashboard already has a "Users" tab (`Livewire\Users`,
  `Icons::USERS`) that means something unrelated: the *monitored app's*
  end-users (who triggered a request, who was affected by an exception),
  not dashboard team members. Reusing that name would be confusing.
- The admin-approved "change my email" workflow (from the original
  request) moves to sub-project 3 (Forgot password) instead of living
  here — it's triggered from the login screen by someone locked out of
  their old email, and its approval UI can't be meaningfully built before
  the request-creation flow it approves exists. Building a page for
  approving a request type that doesn't exist yet would be premature.

## Scope

In scope:
- `monitor_invitations` table: `email`, `role`, `token` (hashed at rest —
  only the plain token appears in the emailed link, mirroring how
  Laravel's own password-reset tokens are stored), `invited_by`
  (`monitor_users.id`), `expires_at`, timestamps.
- Inviting a member: owner or admin picks an email + role (`admin` or
  `viewer` — inviting someone directly as `owner` isn't offered; ownership
  only moves via explicit transfer) and sends an invite. Re-inviting an
  email that already has a pending invite refreshes its token,
  `expires_at`, and `role` (whatever the re-inviter picks this time) —
  and reassigns `invited_by` to whoever just re-sent it, since that's who
  should be able to cancel it afterward under the "admin can only cancel
  their own" rule. Inviting an email that's already a `monitor_users` row
  is rejected with a clear validation error.
- Invite email: the package's first piece of outbound mail — a Mailable
  sent via the *host app's* configured mail driver (the package has no
  mail config of its own; this matches how every other Laravel package
  sends mail). Contains a link to `/monitor/invitations/{token}`.
- Invite expiry: 2 hours. An expired token shows a clear "this invite has
  expired, ask an owner/admin to resend it" message rather than a bare
  404/500.
- Accepting an invite: `GET/POST /monitor/invitations/{token}` — a plain
  controller (same "owns its own route, simple one-time form" pattern as
  `SetupController`/`LoginController`), reachable **without** being
  authenticated (same placement as `/monitor/setup` and `/monitor/login`
  — outside the `EnsureMonitorAuthenticated` group, still behind the
  `viewMonitor` Gate). Shows a name + password (+ confirmation) form; on
  submit, creates the real `MonitorUser` with the role fixed at invite
  time, deletes the `monitor_invitations` row, logs the new user in, and
  redirects to the dashboard.
- The **Team** page: a new Livewire component + nav entry (icon distinct
  from the existing `Icons::USERS`), listing current members (name,
  email, role, joined date) and pending invites (email, role, expires),
  with the actions below available inline, matching the Issues page's
  "list + row actions" pattern rather than the plain-controller pattern
  used for the one-shot auth pages.
- Role changes, remove, leave, and transfer ownership — see the
  permission matrix below.

Out of scope (unchanged from the original decomposition):
- Forgot-password flow, email-change-request-and-approval (sub-project 3).
- OAuth/passkey/TOTP login methods (sub-project 4).

## Permission matrix

| Action | Owner | Admin | Viewer |
|---|---|---|---|
| Invite a member | ✓ | ✓ | ✗ |
| Cancel a pending invite | ✓ (any) | ✓ (**only invites they sent themselves** — `invited_by === actor.id`) | ✗ |
| Change a member's role | ✓ | ✗ | ✗ |
| Remove a member | ✓ | ✗ | ✗ |
| Leave | ✓ (blocked if sole owner — must transfer first) | ✓ | ✓ |
| Transfer ownership | ✓ | ✗ | ✗ |

Additional rules:
- A member can't remove or change the role of themself through the
  generic "manage a member" actions — self-service is only through
  "Leave" and (for the owner) "Transfer ownership".
- Transferring ownership sets the target member's role to `owner` and
  **automatically demotes the previous owner to `admin`** — they keep
  dashboard access, just at the next rung down, rather than needing a
  second manual step.
- Removing a member or changing their role takes effect on their very
  next request with no extra session-invalidation work needed: Laravel's
  session guard re-resolves the authenticated user from the database on
  every request (it isn't cached across requests), so a demoted viewer
  loses `canManageSettings()` immediately, and a removed member's session
  simply stops resolving to a user at all (`EnsureMonitorAuthenticated`
  then redirects them to login, same as any other unauthenticated
  visitor). This is existing framework behavior, not something this
  sub-project has to build.

## Testing plan

- `monitor_invitations` migration: table/columns exist, token stored
  hashed (never the plain value).
- Inviting: owner/admin can invite; viewer gets 403; inviting an
  already-pending email refreshes rather than duplicates; inviting an
  existing member's email is rejected with a validation error; the
  Mailable is actually queued/sent (`Mail::fake()` + assert).
- Cancelling: owner can cancel any pending invite; admin can cancel only
  their own; admin gets 403 trying to cancel someone else's; viewer gets
  403 on both.
- Accepting: valid unexpired token shows the form and creates the user
  with the invited role on submit, logged in afterward; expired token
  shows the expired-state message and creates nothing; unknown token
  404s; accepting deletes the `monitor_invitations` row so the token
  can't be reused.
- Role changes: owner can change a member's role; admin/viewer get 403;
  a member can't change their own role through this action.
- Remove: owner can remove a member (their session stops authenticating
  on the next request); admin/viewer get 403; owner can't remove
  themself through this action (must leave/transfer instead).
- Leave: any authenticated member can leave except a sole owner, who
  gets a clear blocking message instead of a 500/DB-constraint error.
- Transfer ownership: owner can transfer to an existing member — target
  becomes `owner`, previous owner becomes `admin`; non-owner gets 403.
