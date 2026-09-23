# Identity — Specification

> Stable document. Describes what this module *is*. Task tracking lives in
> `TODO.md`.

## Purpose

Identity owns who a person is *to the platform*: an account, how it proves
itself, and the handful of attributes that belong to the account rather than to
any domain process. It absorbs LanCore's Auth, Profile and Steam-auth domains,
with Steam demoted from a special case to one social login driver among several.
The defining constraint is negative: the User model stays slim. LanCore's User
imported models from thirteen domains and carried thirty methods, which is the
single reason that codebase could not be modularized (lessons-from-lancore #2).
Everything a module wants to know about a person beyond identity, the module
keeps as its own read model.

## Owns

- **User**, deliberately narrow: ULID, display name, email, password hash,
  locale, timezone, account state, typed preference values, and the
  profile-of-record (ADR-0051 §2, ADR-0052, ADR-0064: `username`, `tagline`,
  `status_emoji`, `social_links`, `profile_visibility`,
  `listed_in_attendees`). Nothing else.
  Account state is `AccountState::Active`/`Locked`/`Anonymized`/`Merged`;
  `pending` is covered by `email_verified_at` instead of its own state.
  `Anonymized` is written by the `Gdpr/` handler; `Merged` (#81) is written
  by `Actions\MergeUserAccounts` and carries `merged_into_user_id` — see
  that Action's docblock for what an operator-performed merge moves and, at
  length, what it deliberately does not.
- **Authentication**: registration of accounts, login and logout, password
  reset, email verification, remember-me, and lockout after failed attempts.
  Fortify's `LoginResponse` and `TwoFactorLoginResponse` are rebound in
  `boot()` — never `registerBindings()`, since Fortify's own provider binds
  them in `register()`, after every module provider's — so a login with an
  outstanding contract text lands on `policies.required` with
  `url.intended` preserved. They read
  `session('compliance.consent_redirect')` rather than asking Compliance
  directly (#205). A passkey login is the third such path and is rebound the
  same way (`ConsentAwarePasskeyLoginResponse`).
- **Passkeys** (WebAuthn) as a sign-in method beside the password and Steam:
  Fortify's `Features::passkeys()` over `laravel/passkeys`, with this module
  supplying the ULID `Models\Passkey` (`Passkeys::usePasskeyModel()`), the
  `PasskeyUser` contract on User, the list and the add/remove surface on the
  Security settings page (both behind `password.confirm`), the 404-not-403
  refusal for another account's credential, and the account-state check on
  login — a passkey enrolled before a lock does not walk past it. Like a
  linked social identity, a passkey is a row referencing the user, never a
  column on User.
  The **relying party is the request host**, re-set per request by
  `Http\Middleware\BindPasskeyRelyingParty` on Fortify's seven passkey
  routes rather than fixed at boot from `app.url`: one instance answers on
  every brand's domain and on the operator console, and WebAuthn binds a
  credential to the host it was created on. So a passkey is per site — a
  credential made on one brand's domain does not sign in on another's. A
  cross-brand passkey would need one shared registrable domain and is out of
  scope. The browser half is hand-written (`resources/js/lib/webauthn.ts`,
  `webauthnCodec.ts`), with no npm dependency.
- **Social login** behind a `SocialProvider` driver contract. A linked identity
  is a row (`provider`, `provider_user_id`, linked-at) referencing the user —
  never a column on User. Steam is a driver, so are Discord, Google, and
  whatever comes next.
- **`RecordBrandMembership`**'s "you already have an account here" notice
  is scoped to the resolved brand's own **operator** (`brands.operator_id`):
  an account is global to the instance — `users` has no `operator_id` —
  so without that join the notice could name another operator's brand on
  this operator's auth page (ADR-0054, #184).
- **Two-factor authentication**: enrolment, confirmation, recovery codes, reset
  by an administrator. A set of recovery codes is **served once** (D-IDP-18):
  Fortify's `GET two-factor.recovery-codes` answers only against a one-time
  grant that `Listeners\MintRecoveryCodesGrant` writes into the session at the
  two moments a fresh set exists — `TwoFactorAuthenticationConfirmed` and
  `RecoveryCodesGenerated` — and that `Http\Middleware\EnsureRecoveryCodesGrant`
  pulls, names the account for and expires after 60 seconds. Everything else
  is 404, not 403: the route simply is not there for a reload, a second tab or
  a later visit. Whoever lost their codes regenerates, which mints a new grant
  and invalidates the old set. The "I saved my recovery codes" checkbox in
  front of the dialog stays what it was, a nudge.
- **Sessions**: the list of a user's active sessions and their revocation,
  read through `Sessions\SessionRepository` so the page does not care which
  driver stores them (D-IDP-17). On `database` that is the `sessions` table
  (`Queries\ActiveSessionsQuery` and two deletes); on `redis` it is a
  per-user index that `Sessions\IndexedRedisSessionHandler` — bound over
  the stock `createRedisDriver()` — writes beside each payload: a meta hash
  per session id (`ip_address`, `user_agent`, `user_id`, `last_activity`)
  and a set per user, both re-expired with `session.lifetime` on every
  write, so expiry needs no garbage collector and no job. On `array`,
  `file` and `cookie` there is nothing to list, and the page says so rather
  than showing an empty list. Sessions from before the handler was deployed
  carry no index entry and appear from their next request onwards; nothing
  backfills them.
  Sessions are brand-scoped, but the scoping mechanism belongs to Platform:
  `ResolveBrand` gives each brand its own session cookie name, so a browser
  only ever carries one brand's session id. Neither store carries a
  `brand_id` (the `sessions` table is Laravel's own, not one this module
  defines), so the list is operator-wide rather than filtered server-side —
  see TODO.md.
- **Account lifecycle**: locking, unlocking, and the anonymization of the
  account's own data when Compliance requests it.
- **The minimal shared Inertia props for the authenticated user**: ULID, display
  name, avatar URL, locale, timezone. LanCore serialized `$user->toArray()` —
  address, phone, Steam data — plus every computed permission on every request
  (lessons-from-lancore #5). Anything beyond these five fields is a deferred or
  optional prop supplied by the module that needs it. `timezone` earned its
  place in #243 because every page renders timestamps and none of them should
  have to ask for the zone; `null` means the person reads on their own device's
  clock.

### What explicitly does not go on User

This list exists so the god object cannot grow back. None of these are relations
on User; each is a read model in the owning module, keyed by the user's ULID:

- registrations, orders, invoices, carts, payment state — Registration, Commerce
- admissions, badges, check-ins — Admission
- shift assignments, team membership, staffer profile — Staffing
- resource bookings, seat claims — Resources
- tournament participation, match history, game accounts — Competition, GameOps
- achievements, presence, chat state — Community
- billing addresses, phone numbers, VAT identifiers — Commerce, as invoice
  recipient data on the Registration that needed them
- consent records, audit trail, retention state — Compliance
- role and permission grants — per-brand data (ADR-0013)
- notification delivery history and inbox — Messaging

## Does not own

| Nearby concept | Owned by |
|---|---|
| Brands, brand resolution, brand-scoped session configuration | Platform |
| Roles, permission grants, `permissions:sync` | Platform for the per-brand data, each module for its own capability enum (ADR-0013) |
| A participant's access right to an event | Admission — an Admission is a person's access right and may exist without a user account (ADR-0005) |
| The commercial act a person performed | Registration |
| Invoice recipient identity, which may be an organization rather than a person | Commerce |
| Consent, policy acceptance, GDPR orchestration, audit | Compliance — Identity supplies a `Gdpr/` handler for its own tables and nothing more |
| Notification channels, delivery, inbox | Messaging — including *notification preferences* (ADR-0041 revised this line, D-MSG-06): the rows live in Messaging, and Identity's settings navigation links to Messaging's preference page |
| Volunteer profile, skills, availability | Staffing |
| Public profile pages, activity feeds, follower graphs | Community |

## Public contract (events)

Events are the only way other modules may react to this one (ADR-0004).
Payloads carry ULIDs and scalars — never Eloquent models.

No code exists yet. Every row below is a proposal and becomes binding when the
event class lands.

| Event | Emitted when | Payload | Status |
|---|---|---|---|
| `UserRegistered` | An account is created | `user_id`, `brand_id` (the brand it was created *through*, ADR-0016), `email`, `display_name`, `locale`, `registered_at` | implemented |
| `UserEmailVerified` | The email address is confirmed | `user_id`, `verified_at` | proposed |
| `UserProfileUpdated` | Account contact/profile data changes | `user_id`, `email`, `display_name`, `username`, `tagline`, `status_emoji`, `social_links`, `visibility` (ADR-0052), `listed_in_attendees` (ADR-0064 — the opt-in to be named on event attendee lists), `locale`, `updated_at` | implemented |
| `UserPreferencesUpdated` | Typed preference values change (notification preferences are *not* these — they live in Messaging per ADR-0041) | `user_id`, `preference_key`, `value` | proposed |
| `UserAuthenticated` | A login succeeds | `user_id`, `brand_id`, `authenticated_at` | proposed |
| `UserAuthenticationFailed` | A login attempt is rejected | `user_id` or `null`, `brand_id`, `reason_code` | proposed |
| `UserAccountLocked` | The account is locked, manually or by policy (first lock only — re-locking emits nothing) | `user_id`, `reason_code`, `locked_at` | implemented |
| `UserAccountUnlocked` | The lock is lifted | `user_id`, `unlocked_at` | implemented |
| `SocialIdentityLinked` | A social provider identity is attached | `user_id`, `provider`, `provider_user_id` | implemented |
| `SocialIdentityUnlinked` | A social provider identity is detached | `user_id`, `provider` | implemented |
| `SocialIdentityAnnounced` | `identity:announce-social-identities` restates every linked identity for backfill. It is not auditable, and only projections may listen to it (competition design §5.6). | `user_id`, `provider`, `linked_at` | implemented |
| `TwoFactorEnabled` | 2FA enrolment is confirmed | `user_id`, `enabled_at` | proposed |
| `TwoFactorDisabled` | 2FA is switched off or reset | `user_id`, `disabled_at` | proposed |
| `UserAnonymized` | Identity's own records for a subject are scrubbed | `user_id`, `anonymized_at` | shipped |
| `AccountsMerged` | An operator merges two accounts belonging to the same person (#81) | `surviving_user_id`, `merged_user_id`, `performed_by_user_id`, `merged_at` | shipped |

`UserAnonymized` reports completion of Identity's part of a deletion. It is not
a signal for other modules to delete their data — that fan-out is Compliance's
(ADR-0004).

`AccountsMerged` is the same shape of seam: it reports that Identity's own
part of a merge is done (the login methods), not that any other module has
moved anything. No listener exists yet in Registration, Commerce, Admission
or Compliance — a merged account's registrations, orders, admissions and
consent records stay on the closed account until one lands (TODO.md
Backlog). Importing a Steam identifier for a migrated account (the fourth
gap #81 named) is a separate, narrower piece of work blocked on #87 and is
not touched here either.

## Consumes

Events from other modules that this module listens to, and the read models it
maintains from them.

| Event | Owning module | Why |
|---|---|---|
| `DataExportRequested` | Compliance | Identity's `Gdpr/` handler contributes the account, social identities and session history to the export |
| `UserDeletionRequested` | Compliance | Anonymize or erase the account per the requested mode, then emit `UserAnonymized` |
| `BrandArchived` | Platform | Revoke sessions bound to the archived brand |

Identity maintains no read models of other modules' data. If it ever needs one,
that is a signal the User model is being asked to grow again.

One read doorway, no model: `Platform\Operators\OperatorSettings::signupsOpen()`
says whether a stranger may create an account right now — the operator's own
switch on the console's Operator settings page and the instance-wide
`SIGNUPS_ENABLED` cap, combined there. `Http\Middleware\EnsureSignupsOpen`
applies it to Fortify's register routes and to the social completion step,
and the login page drops its "Sign up" link on the same answer. Account
creation only: login and every existing account are untouched.

## Capabilities

Declared in `src/Enums/Permission.php`. Roles that grant them are per-brand
data, not code (ADR-0013).

Proposed catalogue: `ViewUsers`, `ManageUsers`, `LockUser`, `UnlockUser`,
`ResetUserTwoFactor`, `ViewUserSessions`, `RevokeUserSessions`,
`ManageSocialProviders`, `ImpersonateUser`. Acting on one's own account is a
policy question, not a capability.

## Mail

ADR-0048 point 5: `User::sendEmailVerificationNotification()` and
`sendPasswordResetNotification()` override the two hooks Laravel's own
`MustVerifyEmail`/`CanResetPassword` contracts call, replacing the stock
`Illuminate\Auth\Notifications\*` sends with `Mail\AuthMailer`, so both
carry the brand's sender identity and branded layout and leave a
delivery-log row instead of going out through Laravel's default mailer.
Laravel still generates and validates the signed link/hashed token; only
delivery moves. `identity.verify-email` and `identity.password-reset` are
contractual — no topic, only the suppression list applies; `identity.welcome`
is the one key with a topic, following the first verification.

| Key | Trigger | Recipient | Topic |
|---|---|---|---|
| `identity.verify-email` | `User::sendEmailVerificationNotification()`, called by Laravel's own `Registered` handling (registration) and the `verification.send` resend route | The account, by id + explicit email/locale (independent of Messaging's recipient projection, which may not exist yet at registration time) | — |
| `identity.password-reset` | `User::sendPasswordResetNotification($token)`, called by Fortify's password-reset-link flow | The account, by id + explicit email/locale | — |
| `identity.welcome` | `Listeners\SendWelcomeOnVerified` on `Illuminate\Auth\Events\Verified` | The account, by id + explicit email/locale | `identity.welcome` |

`AuthMailer::resolveBrandId()` prefers the ambient `BrandContext` (the
request the link was issued or followed from) and falls back to the
account's earliest `BrandMembership` when there is none (console/queue
context); a user with neither is skipped with a `Log::warning` rather than
guessed at, the `PromoteFromWaitlist::notify()` shape. The verification URL
is built exactly as Laravel's own `VerifyEmail::verificationUrl()` —
`URL::temporarySignedRoute(..., absolute: true)`, never prefixed with
`BrandUrl::baseFor()`, because a signed route's signature is checked
against the exact absolute URL it is later requested at; prefixing a
relative signed path with a brand base would produce a URL whose signature
never validates. The welcome mail's `shopUrl` is set only when
`Feature::for($brand)->active('commerce:shop')` (Pennant, no Commerce
import).

## Activation

Kernel module. Always active for every brand and not gateable: its provider sets
`$gateable = false` and its routes carry no `ensure-module` middleware. There is
no configuration of the system in which authentication is optional. Which social
login drivers a brand offers is per-brand configuration, not module activation.

## Open questions

- ~~Is an account global across the operator's brands, or per brand?~~
  **Settled by ADR-0016:** one `users` row per human across the operator
  (global unique email); per-brand first-seen, roles and preferences live in
  `brand_memberships`. `UserRegistered.brand_id` names the brand the account
  was created through, not an owner.
- ~~Does a person who only ever used brand A have any presence on brand B —
  in lists, on profile pages, in consents, in sends?~~ **Settled by
  ADR-0054:** presence is the membership. An account with no
  `brand_memberships` row on a brand has nothing shown and nothing sent
  there; the account itself is named on every auth surface together with the
  operator's other brands (#184), and a duplicate registration is answered
  as ADR-0016 requires, with the explanatory mail (#18).
- Can a person exist as a registrant without ever holding an account (guest
  registration)? ADR-0005 separates Registration from Admission, which suggests
  yes — and then Compliance needs a subject identity that is not a user ULID.
  **Decision log Round 5 (D-REG-08): deferred — Phase 1 registrants must hold
  an account. Reopen with its own ADR when guest registration is scheduled.**
- ~~Does anonymization keep a tombstone row so foreign ULID references stay
  resolvable, or delete the row and leave dangling references that modules
  must tolerate?~~ **Decision log Round 6 (D-BATCH):** anonymize with a
  tombstone row surviving, so foreign ULID references (an `Admission.holder_
  user_id`, a `StafferProfile`'s user reference, and so on) stay resolvable.
  Mirrors Compliance's own settled deletion-default question.
- ~~Which social drivers ship at release, and does a non-gaming brand ever
  need Steam at all? **Still open** — untouched by the Round 6 gate review.~~
  **Decision log Round 7 (D-IDN-01), amended 2026-08-19:** **Steam only** at
  release, via Socialite behind the `SocialProvider` contract; ordinary
  email/password signup is the other way in. Google and Discord are dropped
  rather than deferred. Steam being OpenID 2.0 means no per-brand credentials
  are needed at all, and `users.password` is nullable because an account
  created this way has none.
- ~~Where the role-assignment screen lives, since it touches both a Platform
  role and an Identity user (mirrored in Platform's open questions).~~
  **Decision log Round 6 (D-BATCH):** Platform owns the role-assignment
  screen; it references the Identity user by ULID.
