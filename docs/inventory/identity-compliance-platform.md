# Identity / Compliance / Platform — LanCore inventory

Scope: who a person is (Identity), what the platform owes them legally
(Compliance), and the kernel every module plugs into (Platform). This is the
largest of the inventory workstreams because LanCore spread these three
concerns across `app/Models/User.php`, `app/Enums/`, `app/Http/Controllers/Settings/`,
`app/Http/Middleware/`, `app/Gdpr/` and five `app/Domain/` folders, with no
single owner for any of them.

## Sources

LanCore (`/home/mkohn/git/lan-software/LanCore`, read-only reference):

- `docs/mil-std-498/SRS.md` §3.2.15 (CSCI-USR, USR-F-001..026), §3.2.18
  (CSCI-ORG, ORG-F-001..006), §3.2.X (Policy, POL-F-001..018), §3.2.Y
  (GDPR-F-001..009), §3.2.Z (Data Lifecycle, DL-F-001..018), §3.2.AA
  (CSCI-THM, THM-F-001..006), §3.6.2 (CSCI-I18N, I18N-F-001..007), §3.8
  (SEC-001..003, SEC-007..013, SEC-021..022).
- `docs/mil-std-498/SSS.md` CAP-USR-001..015, CAP-ORG-001..004,
  CAP-POL-001..009, CAP-GDPR-001..004, CAP-DL-001..008, CAP-THM-001..004.
- `docs/mil-std-498/DBDD.md` §4.1.1 (`users` table, 30 columns).
- Code: `app/Models/User.php`, `app/Models/OrganizationSetting.php`,
  `app/Domain/{Auth,Profile,Policy,DataLifecycle,Theme}/`, `app/Gdpr/`,
  `app/Enums/{Permission,RolePermissionMap,RoleName,AuditPermission}.php`,
  `app/Concerns/HasPermissions.php`, `app/Http/Controllers/Settings/*`,
  `app/Http/Controllers/{OrganizationSettingsController,LegalController}.php`,
  `app/Http/Middleware/{HandleInertiaRequests,ResolveEventTheme,SetLocale}.php`,
  `routes/{settings,users,policies,data-lifecycle,themes}.php`.

Konventix: `CLAUDE_extra.md`, `docs/architecture/overview.md`,
`docs/architecture/module-map.md`, `docs/lessons-from-lancore.md`,
`docs/decisions/0001,0003,0004,0008,0009,0010,0013,0014`, and the existing
`SPEC.md` / `TODO.md` of `app-modules/{identity,compliance,platform}`.

### Note on the `SET-` requirement family

The brief lists a **SET (25)** family as the settings surface. In LanCore's SRS
`SET-F-*` is the **Seating** CSCI (§3.2.5) — it belongs to the Resources
workstream, not this one. LanCore's actual settings surface is split three ways
and has no single ID family:

- **ORG-F-001..006** — the operator/organization identity key-value store.
- The `/settings/*` route group in `routes/settings.php` — nine user-facing
  preference pages, most of which are covered by USR-F-008..010, USR-F-021,
  USR-F-025 and by requirements owned by *other* domains (SET-F-010 seating
  privacy, NLT-F-003 email, notification preferences).
- Ambient `.env`/config adaptation (SRS §3.6.1) and I18N-F-001..003.

The "Single-instance → multi-brand" table below covers all three together, and
uses the LanCore requirement ID where one exists. There is no clean 25-row
`SET-F` family to port; that section is my reconstruction of one.

---

## Feature inventory

Verdicts: **Port as-is** / **Port renamed** / **Generalize** / **Split across
modules** / **Drop** / **Defer post-release**.

This came out at 130 rows against a suggested 50–80. The overshoot is real
surface, not padding: this scope covers ~90 distinct SRS requirements across six
families plus three requirement-free areas LanCore never specified (Steam auth,
sidebar state, cookie preferences). Rows were kept separate wherever two
features have different owning modules or different verdicts, because merging
those is how a feature gets silently dropped in the port. Consolidate freely if
the density is unhelpful — the grouping headers are the natural seams.

### Authentication and account (Identity)

| LanCore feature | SRS ID | What it does today | Konventix module | Proposed name | Verdict |
|---|---|---|---|---|---|
| Fortify registration + email verification | USR-F-001 | `CreateNewUser` action wired through Fortify; verification mail on create | Identity | `RegisterUser` action | Port as-is |
| Email + password login | USR-F-002 | Fortify login, throttled | Identity | — | Port as-is |
| TOTP 2FA with recovery codes | USR-F-003, SEC-002 | `TwoFactorAuthenticatable` trait, secret + recovery codes as encrypted columns on `users` | Identity | `TwoFactorCredential` (own table) | Port renamed |
| Password reset via signed email link | USR-F-004 | Fortify broker | Identity | — | Port as-is |
| Password confirmation for sensitive ops | USR-F-005 | `password.confirm` middleware on `SecurityController::edit` | Identity | — | Port as-is |
| bcrypt rounds configurable | SEC-001, §3.6.1 | `.env` `BCRYPT_ROUNDS` | Identity (config) | — | Port as-is |
| Encrypted server-side sessions | SEC-003 | `sessions` table, single global scope | Identity + Platform | brand-scoped session | Split across modules |
| Session list / revocation | — | **Does not exist as UI.** `sessions` rows are only readable through `app/Gdpr/SessionsDataSource.php` | Identity | `ActiveSession` list + `RevokeSession` | Generalize |
| Steam OpenID 2.0 sign-in | — (no SRS row) | `Auth/Steam/SteamAuthController` redirect/callback via Socialite; hardcoded as *the* social login | Identity | `SocialProvider` driver `steam` | Generalize |
| Steam account link / unlink | — | `LinkSteamAccount`, `UnlinkSteamAccount`; writes `users.steam_id_64` + `steam_linked_at` **columns** | Identity | `SocialIdentity` row (`provider`, `provider_user_id`, `linked_at`) | Generalize |
| Steam-only accounts without a password | — | `hasUsablePassword()`, `SteamLinkStatus::{Linked,SteamOnly,NotLinked}`, `scopeWhereSteamStatus` | Identity | account state `passwordless` | Generalize |
| Social sign-up completion form | — | `PendingSteamRegistration` DTO stashed in session, `/auth/steam/complete` collects email + policy acceptances | Identity | `CompleteSocialRegistration` (driver-agnostic) | Generalize |
| Linked accounts settings page | — | `settings/LinkedAccounts` — hardcoded single `steam` prop | Identity | Connected accounts (list of drivers) | Generalize |
| Forced username onboarding | USR-F-022 | `RequireUsername` middleware redirects to `/onboarding/username` until chosen; allowlists logout/lang/assets | Identity | `CompleteAccountSetup` gate | Generalize |
| `username` column | USR-F-022 | `VARCHAR(32)`, case-insensitive unique, regex `^[A-Za-z0-9][A-Za-z0-9_-]{1,30}[A-Za-z0-9]$` | Identity | `handle` | Port renamed |
| `name` (real/legal name) column | USR-F-005, USR-F-012 | Used for invoices, admin lists, entrance lists — and never publicly | Commerce / Registration | `legal_name` on invoice recipient | Split across modules |
| Address block on `users` | USR-F-012, SEC-021 | `phone`, `street`, `city`, `zip_code`, `country` on the users row, carved out of public responses by SEC-021 | Commerce | invoice recipient data | Split across modules |
| `hasCompleteProfile()` purchase gate | USR-F-013 | Blocks cart-item add until address + phone/email present | Commerce | checkout completeness rule | Split across modules |
| Stripe Cashier `Billable` on User | USR-F-011 | `stripe_id`, `pm_type`, `pm_last_four`, `trial_ends_at` columns | Commerce | PSP customer reference (ADR-0007) | Split across modules |
| `locale` column + profile selector | USR-F-021, I18N-F-001 | `en/de/fr/es` or NULL | Identity | — | Port as-is |
| Per-user timezone | §3.6.1 | **Installation-wide only**, no per-user column | Identity | `timezone` on User | Generalize |
| `email_hash` salted lookup column | DL-F-016 | HKDF-derived HMAC kept in sync by a `saving` hook so anonymized users stay findable | Identity (column) / Compliance (consumer) | — | Port as-is |
| Account soft-delete + `anonymized_at` / `pending_deletion_at` | DL-F-009 | Three overlapping state flags on `users` | Identity | single `AccountState` enum (`pending`, `active`, `locked`, `anonymized`) | Generalize |
| Failed-login lockout | — | **Missing.** Only Fortify's rate limiter; no account lock, no `UserAccountLocked` concept | Identity | `LockAccount` / `UnlockAccount` | Generalize |

### Profile and public identity

| LanCore feature | SRS ID | What it does today | Konventix module | Proposed name | Verdict |
|---|---|---|---|---|---|
| Public profile page `/u/{username}` | USR-F-023, CAP-USR-012 | `PublicProfileController` + `pages/u/Show.vue`; 404 (not 403) on visibility failure | Community | public profile | Split across modules |
| `profile_visibility` (public / logged_in / private) | USR-F-025, SEC-021 | Column on `users`, radio group on `settings/Privacy.vue` | Community (rule) + Identity (nothing) | profile visibility | Split across modules |
| Profile preview bypassing visibility | USR-F-026 | `GET /settings/profile/preview` renders the public page as if `public` | Community | — | Split across modules |
| Avatar source chain | USR-F-024 | `avatar_source` enum `default/gravatar/custom/steam`, `ResolveAvatarUrl` guarantees non-null | Identity | `avatar_url` (part of the 4-field shared prop) | Port as-is |
| Avatar normalization | USR-F-024, SEC-022 | `NormalizeAvatar` → 1000×1000 WebP, 5 MB ingress cap, mime allowlist, deletes previous file | Identity | — | Port as-is |
| Profile banner | USR-F-024, SEC-022 | `NormalizeBanner` → 1500×500 WebP; only ever rendered on the public profile | Community | profile banner | Split across modules |
| `short_bio`, `profile_description`, `profile_emoji` | USR-F-024 | Public-profile-only text columns on `users` | Community | — | Split across modules |
| `profile_updated_at` diff marker | USR-F-024 | Exists solely to drive `ProfileUpdated` webhook diffing | Extensibility | — | Defer post-release |
| `ProfileDataSource` GDPR export | CAP-GDPR-001 | Exports the whole users row | Identity | Identity `Gdpr/` handler | Port renamed |

### User preferences and the `/settings` surface

| LanCore feature | SRS ID | What it does today | Konventix module | Proposed name | Verdict |
|---|---|---|---|---|---|
| Appearance light/dark/system | USR-F-010, CAP-USR-008 | Cookie + localStorage, `HandleAppearance` middleware; explicitly *not* the Theme Library | Identity (value) + Platform (application) | `color_scheme` preference | Split across modules |
| Ticket discovery visibility | USR-F-008, CAP-USR-006 | `is_ticket_discoverable` + `ticket_discovery_allowlist` JSON matched **by user name string** | Registration | participant discoverability | Split across modules |
| Public seat-name visibility | SET-F-010 | `is_seat_visible_publicly` + `User::isSeatNameVisibleTo()` — a *seating* rule living on User | Resources | booking visibility | Split across modules |
| Sidebar favorites | USR-F-009 | `sidebar_favorites` JSON of route names on `users` | Platform | navigation favorites (per user, per brand) | Port renamed |
| Sidebar collapsed group state | — | `sidebar_collapsed_groups` JSON, no SRS row | Platform | navigation UI state | Port renamed |
| Notification preferences | SET-F-014 | `notification_preferences` table with one boolean pair per feature (`mail_on_seating`, `push_on_seating`, …) | Messaging | channel preferences | Split across modules |
| Web-push subscriptions | — | `push_subscriptions` hasMany on User | Messaging | — | Split across modules |
| Newsletter email settings page | NLT-F-003 | `settings/email` diffs Listmonk subscriptions | Newsletter | — | Split across modules |
| Cookie preferences | — | `cookie_preferences` JSON blob on `users`, shared as an Inertia prop, **no SRS row and no policy link** | Compliance | cookie consent record | Generalize |
| Privacy settings page | USR-F-025, SET-F-010, SET-F-011 | One page mixing profile visibility, seat visibility, and consent withdrawal — three modules' concerns | split | — | Split across modules |

### Authorization (requirements for the permissions workstream, not its design)

| LanCore feature | SRS ID | What it does today | Konventix module | Proposed name | Verdict |
|---|---|---|---|---|---|
| Five static roles | USR-F-006, CAP-USR-002 | `RoleName` enum: User, Moderator, Admin, Superadmin, SponsorManager; `roles` pivot; adding a role needs a deploy | Platform | brand-scoped role rows (ADR-0013) | Generalize |
| Per-domain `Permission` enums | USR-F-014 | 23 enums implementing `PermissionEnum` (27 cases), plus a separate `AuditPermission` | every module | `src/Enums/Permission.php` | Port as-is |
| Static `RolePermissionMap::forRole()` | USR-F-015 | Role→permission mapping hardcoded in one 200-line file; `::all()` collects every case | Platform | `permissions:sync` projecting enums into rows | Generalize |
| `HasPermissions` trait with request cache | USR-F-016 | `hasPermission`, `hasAnyPermission`, `allPermissions` on User | — | spatie/laravel-permission | Drop |
| Superadmin bypass | USR-F-017, SEC-012 | Single `Gate::before()` in `AppServiceProvider` — an operator-wide god mode with no audit hook | Platform | explicit operator capability | Generalize |
| Permissions in shared Inertia props | USR-F-018, SEC-013 | Flat string array of *every* resolved permission on *every* response (lessons #5) | — | per-page authorization props | Drop |
| `usePermissions()` Vue composable | USR-F-019 | Typed `can()` / `canAny()` over a hand-maintained TS `Permission` const object | Platform | generated from enums (ADR-0009) | Generalize |
| Permission-conditional sidebar | USR-F-020 | 1,285-line sidebar component enumerating every domain (lessons #10) | Platform | navigation registry filters by feature + permission | Port renamed |
| Policies on domain entities | SEC-007, SEC-008 | 29 policies, per-policy `before()` removed in favour of the global Gate | every module | `src/Policies/` | Port as-is |
| `UserRolesChanged` / `UserAttributesUpdated` | USR-F-007 | Two events, one carrying both role and profile change | split | `RoleGrantsChanged` (Platform), `UserProfileUpdated` (Identity) | Split across modules |
| Backstage user administration | USR-F-014 | `/backstage/users` index, show, update, `updatePersonalData`, bulk role assign, bulk destroy | Identity (screens) + Platform (grants) | user administration | Split across modules |

### Policy and consent (Compliance)

| LanCore feature | SRS ID | What it does today | Konventix module | Proposed name | Verdict |
|---|---|---|---|---|---|
| PolicyType CRUD | POL-F-002, POL-F-003 | Inline create/update/delete from the policies index; `restrictOnDelete` with `PolicyTypeInUseException` | Compliance | `PolicyType` | Port as-is |
| Policy entity | POL-F-001, CAP-POL-001 | Stable `key`, name, description, `sort_order`, `archived_at`, `is_required_for_registration`; **operator-wide, no brand** | Compliance | `Policy` (brand-scoped) | Generalize |
| Linear immutable version history | POL-F-007, CAP-POL-002 | `PublishPolicyVersion` locks the parent `for update`, computes `version_number` per `(policy_id, locale)` | Compliance | `PolicyVersion` | Port as-is |
| PDF snapshot on publish | POL-F-007 | dompdf render to the private storage role at `policy-versions/{id}.pdf` | Compliance | — | Port as-is |
| Editorial vs non-editorial publish | POL-F-005, POL-F-006, POL-F-008, CAP-POL-003 | One boolean at publish time decides silent vs. rights-affecting; non-editorial requires a `public_statement` (422 otherwise) and moves `required_acceptance_version_id` | Compliance | — | Port as-is |
| Per-locale version drafts | — | `PolicyLocaleDraft` model + draft CRUD routes, no SRS row | Compliance | — | Port as-is |
| Registration-time acceptance gate | POL-F-009, POL-F-010 | `CreateNewUser` rejects (422) unless `accepted_policy_version_ids[]` covers every required current version; writes acceptances with `source='registration'` | Compliance (rule) + Identity (form) | — | Split across modules |
| `policy_acceptances` records | POL-F-010, POL-F-012, CAP-POL-004 | `user_id` + version, locale, IP, user-agent, `source` enum (registration / re_acceptance_gate / settings / checkout / manual_admin); upsert un-withdraws | Compliance | `ConsentRecord` keyed by `subject_id` | Generalize |
| Re-acceptance gate middleware | POL-F-011, CAP-POL-006 | `RequirePolicyAcceptance` redirects to `/policies/required`, stashes intended URL, allowlists itself and the public policy page | Compliance | — | Port as-is |
| Consent withdrawal | POL-F-013, CAP-POL-007 | `WithdrawPolicyConsent` stamps `withdrawn_at/reason/ip/user_agent`; `NoActivePolicyAcceptanceException` when none | Compliance | — | Port as-is |
| Admin notification on withdrawal | POL-F-014, CAP-POL-008 | Mail + database notification to every `ManagePolicies` holder except the withdrawer | Compliance (event) + Messaging (delivery) | — | Split across modules |
| Version-published email fan-out | POL-F-015, POL-F-016 | `chunkById(500)` over distinct prior acceptors, one job each, PDF attached, recipient `locale` honoured | Compliance (event) + Messaging (delivery) | — | Split across modules |
| Two-step publish confirmation | POL-F-018 | Modal with a 5-second delayed-enable button labelled "Send {N} emails and publish" | Compliance | — | Port as-is |
| Public policy page + legal index | — | `/policies/{policy:key}` bound on the stable key; `LegalController::index` lists active policies | Platform (renders) + Compliance (content) | block CMS page pulling the current version | Split across modules |
| Policy audit feed in admin UI | POL-F-004, POL-F-017, CAP-POL-009 | `/backstage/policies/{policy}` shows version history plus an `audits`-table feed | Compliance | — | Port as-is |
| Shop checkout-condition acknowledgement | SHP-F-010, SHP-F-011 | Deliberately **separate** storage and middleware from the Policy domain | Commerce | — | Split across modules |

### GDPR export (Compliance orchestration + per-module handlers)

| LanCore feature | SRS ID | What it does today | Konventix module | Proposed name | Verdict |
|---|---|---|---|---|---|
| `GdprDataSource` contract | CAP-GDPR-001 | `key()`, `label()`, `for(User, GdprExportContext): GdprDataSourceResult` — one implementation per domain | Compliance (contract) | `GdprExportHandler` | Port renamed |
| 13 per-domain data sources | GDPR-F-007 | Profile, Policy, Sessions, Audits, Shop, Ticketing, Competition, News, Notification, OrgaTeam, Sponsoring, Achievements, Chat | each owning module | `src/Gdpr/` | Port as-is |
| Central `SOURCES` const registration | — | `Policy\Providers\GdprServiceProvider::SOURCES` — one hardcoded array listing every domain (the ADR-0003 anti-pattern) | Compliance | self-registration from each module's provider | Drop |
| Cross-user pseudonymization | GDPR-F-005, CAP-GDPR-002 | `GdprExportContext::obfuscateUser()` issues `user_a`, `user_b`, …; manifest maps pseudonym → hint label only, never → real id | Compliance | — | Port as-is |
| AES-256 per-entry ZIP encryption | GDPR-F-003, CAP-GDPR-003 | `ZipArchive::setEncryptionName(..., EM_AES_256, $password)` on every entry | Compliance | — | Port as-is |
| Accepted policy PDFs in the archive | GDPR-F-006, CAP-GDPR-004 | Verbatim copy to `policy_acceptances/{policy-key}-v{n}.pdf` | Compliance | — | Port as-is |
| `manifest.json` | GDPR-F-007 | Timestamp, `app_version`, registered sources with record counts, pseudonym table | Compliance | — | Port as-is |
| `gdpr:export-user` artisan command | GDPR-F-001, GDPR-F-002 | Interactive Laravel-Prompts wizard, `--password`, `--include-soft-deleted`, `--output-dir`; **no request entity, no deadline, no self-service** | Compliance | `SubjectRequest` of type `export` with a state machine and an expiring download | Generalize |
| Export written to local storage | GDPR-F-008 | `storage/app/gdpr-exports/{user-id}-{Y-m-d_His}.zip`, path printed to the operator | Compliance | archive with `archive_expires_at` | Generalize |
| Admin-triggered export endpoint | — | `AdminUserGdprExportController::store` | Compliance | — | Port renamed |
| `email_hash` fallback lookup | GDPR-F-009, CAP-DL-007 | Falls back to salted hash + `withTrashed()` so anonymized subjects stay discoverable | Compliance | — | Port as-is |
| `SessionsDataSource` / `AuditDataSource` at app root | — | Two sources living in `app/Gdpr/` because they belong to no domain | Identity / Compliance respectively | — | Split across modules |

### Deletion and retention (Compliance)

| LanCore feature | SRS ID | What it does today | Konventix module | Proposed name | Verdict |
|---|---|---|---|---|---|
| Self-service deletion request | DL-F-001, CAP-DL-001 | `/portal/account/delete` requires current password, creates `DeletionRequest` in `pending_email_confirm`, sets `users.pending_deletion_at`, issues a SHA-256 one-shot token | Compliance (state) + Identity (screen) | `SubjectRequest` type `deletion` | Split across modules |
| Admin-initiated deletion | DL-F-002, CAP-DL-002 | Same flow with `requested_by_admin_id`; the subject still gets the confirmation mail | Compliance | — | Port as-is |
| Email confirmation → 30-day grace | DL-F-003, CAP-DL-003 | `pending_grace`, `scheduled_for = now()+30d`, token cleared | Compliance | — | Port as-is |
| Cancellation, incl. signed link | DL-F-004 | `isCancellable()` guard; `cancel-via-link` signed route works after logout | Compliance | — | Port as-is |
| Read-only account during grace | DL-F-006 | `EnforceAccountReadOnlyDuringGrace` blocks all non-GET except cancel/confirm/self-export/logout with HTTP 423 | Compliance | — | Port as-is |
| Duplicate-request rejection | DL-F-008 | `RuntimeException` when an active request exists | Compliance | — | Port as-is |
| `UserAnonymizer` PII scrub | DL-F-009, CAP-DL-004 | Writes via `DB::table` to bypass the email mutator: name → `Deleted User #<hex>`, username → `deleted_<id>`, email → `deleted-<id>@anonymized.invalid`, clears PII, randomizes the password hash | Identity's own handler | Identity `Gdpr/` erasure handler | Split across modules |
| `DomainAnonymizer` registry | DL-F-010 | 12 anonymizers run in registration order, one append-only `AnonymizationLogEntry` per domain | Compliance (registry) + each module | `GdprErasureHandler` | Port renamed |
| Anonymization log admin view | DL-F-010 | `AdminAnonymizationLogController` index | Compliance | — | Port as-is |
| `RetentionEvaluator` registry | DL-F-011, CAP-DL-005 | Accounting, Audit, Consent evaluators return a hold verdict + window | Compliance (contract) + owning modules | retention evaluators | Generalize |
| `AccountingEvaluator` hold rule | DL-F-011 | Holds any user with an order, a `stripe_id`, or a subscription | Commerce | — | Split across modules |
| `RetentionPolicy` admin CRUD | DL-F-012 | Per-data-class rows, `retention_days`, `can_be_force_deleted`, `updated_by_user_id`, audited | Compliance | `RetentionRule` (avoid clashing with Laravel policies) | Port renamed |
| `RetentionDataClass` enum with legal basis | — | 13 classes, each carrying its statutory justification (HGB §257 / AO §147 → 3650 days; sessions → 30) | Compliance | `DataCategory` | Port as-is |
| Nightly purge | DL-F-013, DL-F-017 | `lifecycle:purge` at 03:15 and `ProcessDueDeletionRequestsJob` at 03:00, both `onOneServer()->withoutOverlapping()`; hard-deletes the users row when every evaluator agrees | Compliance publishes `RetentionPeriodElapsed`; owning modules delete their own rows | — | Generalize |
| Force delete bypassing retention | DL-F-015, CAP-DL-006 | Requires a non-empty reason, runs anonymizers in `PurgeNow` mode, hard-deletes the row, stamps `force_deleted_at`; pinned policies still hold | Compliance | — | Port as-is |
| Deletion lifecycle mails | DL-F-001..004 | `DeletionConfirmationMail`, `DeletionScheduledMail`, `DeletionCancelledMail` sent by domain listeners | Compliance (events) + Messaging (delivery) | — | Split across modules |
| Events are soft-delete-only | DL-F-018, CAP-DL-008 | `EventPolicy::forceDelete` returns `false` unconditionally to preserve attendance/accounting history | Event | — | Split across modules |
| DataLifecycle `Permission` enum | DL-F-014, SEC-DL-001 | `RequestUserDeletion`, `ForceDeleteUserData`, `ManageRetentionPolicies`, `ViewDeletionRequests`; force-delete reserved for Superadmin | Compliance | — | Port as-is |

### Audit (Compliance)

| LanCore feature | SRS ID | What it does today | Konventix module | Proposed name | Verdict |
|---|---|---|---|---|---|
| `owen-it/laravel-auditing` on 124 models | POL-F-017, CAP-POL-009, SEC-DL-002 | Model-level `Auditable` trait writes attribute diffs to one `audits` table; `$auditExclude` per model hides secrets and UI state | Compliance | audit trail | Generalize |
| Per-entity audit viewers | POL-F-004 | 13 separate `*AuditController` classes (Event, Ticket types, Sponsor, SeatPlan, Voucher, News, Program, User-on, User-by, …), each with its own page | Compliance | one audit viewer, filtered by subject | Generalize |
| `AuditPermission::ViewAuditLogs` | USR-F-014 | A single cross-cutting enum outside the per-domain scheme | Compliance | `ViewAuditLog` | Port renamed |
| Audit rows in the GDPR export | — | `AuditDataSource` exports rows where the subject is actor *or* auditable, pseudonymizing other actors | Compliance | — | Port as-is |
| Audit retention | DL-F-011 | `AuditEvaluator` holds audits for 3650 days, aligned with accounting | Compliance | — | Port as-is |

### Theming (Platform)

| LanCore feature | SRS ID | What it does today | Konventix module | Proposed name | Verdict |
|---|---|---|---|---|---|
| Theme admin CRUD | THM-F-001, CAP-THM-001 | Resourceful `ThemeController` + `setDefault`, gated by `ManageThemes` | Platform | `TokenSet` administration | Port renamed |
| `light_config` / `dark_config` JSON | THM-F-002 | Two nullable JSON maps of CSS custom properties; keys validated against `PaletteVariables::allowedKeys()`; values reject `;`, `}`, `<` to stop breakout from the inlined `<style>` block | Platform | semantic token set (ADR-0008) | Generalize |
| `PaletteVariables` allowlist | SDD §5.11 | ~30 variables in named groups (surface, brand, sidebar, …) driving the editor layout; **raw shadcn variables, not semantic tokens** | Platform | semantic token vocabulary | Generalize |
| `ThemePolicy` / `ManageThemes` | THM-F-003 | viewAny/view/create/update/delete; 403 for non-admins | Platform | `ManageBrandTheme` | Port renamed |
| `events.theme_id` assignment | THM-F-004, CAP-THM-002 | Nullable FK, `nullOnDelete()`, assignment endpoint, change captured in the Event audit trail | Platform + Event | brand theme, with optional per-event override | Generalize |
| Theme resolution middleware | THM-F-005, CAP-THM-004 | `ResolveEventTheme`: per-event `theme_id` → `OrganizationSetting['default_theme_id']` → null; only inside `/events/{event}/...`; caches the default id for 1h with a `__none__` sentinel | Platform | brand → event override → none | Generalize |
| `activeTheme` shared prop + server-side injection | THM-F-005, CAP-THM-003 | `View::share` feeds the Blade root (two `<style>` blocks) and the Inertia prop, keeping SSR and client consistent; no `data-theme`, no vendor stylesheet | Platform | — | Port as-is |
| Site-wide default theme endpoint | THM-F-006 | `PATCH /themes/default` persists `OrganizationSetting['default_theme_id']`, invalidates `inertia.activeTheme.default_id` | Platform | per-brand default | Generalize |
| Contrast validation | — | **Does not exist.** No BFSG check anywhere in the Theme domain | Platform | token-set contrast validation, refusing non-compliant sets (ADR-0008) | Generalize |
| User `dark` class never suppressed by a theme | CAP-THM-004 | Personal appearance preference always wins over the palette | Platform | — | Port as-is |

### Organization settings, branding and i18n

| LanCore feature | SRS ID | What it does today | Konventix module | Proposed name | Verdict |
|---|---|---|---|---|---|
| `OrganizationSetting` key/value store | ORG-F-001 | Untyped `key` → JSON `value` rows, read with magic strings from 8 call sites (lessons #11) | Platform | typed `OperatorSetting` + `BrandSetting` value objects | Generalize |
| Organization identity fields | ORG-F-001, CAP-ORG-001 | name, logo, `address_line1/2`, email, phone, website, `tax_id`, `registration_id`, `legal_notice` | Platform + Commerce | split operator vs brand (see next section) | Split across modules |
| Admin-only settings routes | ORG-F-002, CAP-ORG-002 | Whole route group behind admin | Platform | `ManageBrands` / `ManageOperator` | Port renamed |
| `organization` shared Inertia prop | ORG-F-003, CAP-ORG-003 | `{ name, logoUrl, hasImpressum }` on every page | Platform | `brand` shared prop | Port renamed |
| Settings prop cache | ORG-F-004, CAP-ORG-004 | `inertia.organization`, 1h TTL, invalidated by three controller methods by hand | Platform | brand-keyed cache invalidated on `BrandRenamed`/`BrandThemeUpdated` | Generalize |
| Logo upload / removal | ORG-F-005 | Public disk, 2 MB, png/jpg/svg/webp; removal deletes the file and nulls the setting | Platform | brand logo | Port as-is |
| Impressum content + `/legal/impressum` | — | `impressum_content` (20k chars) and `impressum_responsible` free text rendered by `LegalController` | Platform | block CMS static page per brand | Generalize |
| Invoice organization block | — | `OrganizationSetting::forInvoice()` returns name/address/tax_id/logo as base64 for PDF rendering | Commerce | invoice issuer | Split across modules |
| LPPS publishing fields | ORG-F-006 | `lpps_description`, `lpps_steam_group_url`, `lpps_discord_invite_url`, `lpps_publisher_unique_id` | Content (Publishing) | — | Defer post-release |
| Four display locales | I18N-F-001 | `en`, `de`, `fr`, `es`; `en` default | Platform | — | Port as-is |
| `SetLocale` middleware | I18N-F-002 | Authenticated → `user.locale`; anonymous → `Accept-Language` nearest match → `en` | Platform (middleware) + Identity (value) | — | Split across modules |
| `locale` / `availableLocales` shared props | I18N-F-003 | Feeds vue-i18n and the locale switcher | Platform | — | Port as-is |
| Weblate translation pipeline | I18N-F-006 | Translator commits pushed to a `weblate` branch, fast-forwarded onto `main` by a workflow | Platform (ops) | — | Port as-is |
| `HandleInertiaRequests` shared props | ORG-F-003, USR-F-018 | 20+ props on every response including `$user->toArray()` and the full permission array (lessons #5) | Platform | minimal shared props + module-registered deferred props | Drop |

**Row count: 130** — Identity 39, Compliance 46, Platform 25, split/deferred to
other modules 20.

---

## Keeping User slim

Every column, relation, trait and method currently on `app/Models/User.php`
(the model that "imported from 13 domains, 30 methods" — lessons #2), and where
it goes. "Stays on User" means the Identity `User` model; anything else becomes
a row in the owning module keyed by the user's ULID, populated by listening to
Identity's events.

| LanCore User capability/relation | Stays on User? | If not, which module owns it and as what |
|---|---|---|
| `id` (already ULID), `created_at` | Yes | — |
| `email`, `email_verified_at` | Yes | — |
| `password`, `remember_token` | Yes | — |
| `two_factor_secret`, `two_factor_recovery_codes`, `two_factor_confirmed_at` | Off User, in Identity | Identity `two_factor_credentials` row — three encrypted columns on the auth table is a Fortify default, not a requirement |
| `email_hash` | Yes | Kept on User; Compliance reads it for post-anonymization subject lookup (DL-F-016) |
| `name` (real/legal name) | **No** | Commerce — `legal_name` on the invoice recipient; Registration may capture it per registration |
| `username` | Yes, renamed `handle` | Identity owns uniqueness; Community renders it |
| `locale` | Yes | — |
| `timezone` (new) | Yes | — |
| `phone`, `street`, `city`, `zip_code`, `country` | **No** | Commerce — invoice recipient data attached to the Registration that needed it |
| `avatar_source`, `avatar_path` | Yes | Required by the four-field shared prop (`avatar_url`) |
| `banner_path` | **No** | Community — profile banner |
| `short_bio`, `profile_description`, `profile_emoji` | **No** | Community — public profile fields |
| `profile_visibility` | **No** | Community — the rule only governs a Community-owned page |
| `profile_updated_at` | **No** | Extensibility — exists only for webhook diffing; post-release |
| `steam_id_64`, `steam_linked_at` | **No** (as columns) | Identity `social_identities` row (`provider`, `provider_user_id`, `linked_at`) — same module, correct shape |
| `stripe_id`, `pm_type`, `pm_last_four`, `trial_ends_at` (`Billable`) | **No** | Commerce — PSP customer reference per settlement adapter (ADR-0007) |
| `is_ticket_discoverable`, `ticket_discovery_allowlist` | **No** | Registration — participant discoverability, and keyed by ULID rather than by name string |
| `is_seat_visible_publicly` | **No** | Resources — booking visibility on the resource booking |
| `sidebar_favorites`, `sidebar_collapsed_groups` | **No** | Platform — per-user, per-brand navigation state in the navigation registry |
| `cookie_preferences` | **No** | Compliance — a consent record against a cookie policy version |
| `anonymized_at`, `pending_deletion_at`, `deleted_at` | Collapsed into one `AccountState` enum on User | Compliance owns the `SubjectRequest` and its clocks; User carries only the resulting state |
| `SoftDeletes` trait | Yes | Needed for the tombstone (see D-IDP-08) |
| `Auditable` trait (owen-it) | **No** | Compliance — audit rows written from events, not from a model trait (see D-IDP-03) |
| `Notifiable` trait | Yes, minimal | Identity keeps routing; Messaging owns channels, preferences, delivery log and inbox |
| `TwoFactorAuthenticatable` trait | Yes | — |
| `HasPermissions` trait (`hasPermission`, `hasAnyPermission`, `allPermissions`) | **No** | spatie/laravel-permission via Laravel's Gate (ADR-0013) |
| `roles()` BelongsToMany | **No** | Platform — brand-scoped role rows with `brand_id` as the spatie team key |
| `hasRole()`, `isAdmin()`, `isSuperadmin()`, `isSponsorManager()` | **No** | Gate checks against capabilities; the last two are already `@deprecated` in LanCore |
| `orders()` | **No** | Commerce |
| `ownedTickets()`, `managedTickets()` | **No** | Registration — a Registration has an owner and a manager |
| `assignedTickets()` (with `checked_in_at` pivot) | **No** | Admission — an Admission is a person's access right and may exist without an account (ADR-0005) |
| `managedSponsors()` | **No** | Sponsoring |
| `comments()` | **No** | Content |
| `achievements()` | **No** | Community |
| `dismissedAnnouncements()` | **No** | Content |
| `notificationPreference()`, `programNotificationSubscriptions()`, `pushSubscriptions()` | **No** | Messaging |
| `policyAcceptances()`, `acceptedPolicyVersions()` | **No** | Compliance — `ConsentRecord` keyed by `subject_id` |
| `hasCompleteProfile()` | **No** | Commerce — a checkout precondition, not an identity fact |
| `isDiscoverableBy()` | **No** | Registration |
| `isSeatNameVisibleTo(?User, Event)` | **No** | Resources — it takes an `Event` as an argument, which is the tell |
| `avatarUrl()` | Yes | Part of the four-field shared prop |
| `bannerUrl()`, `profileUrl()` | **No** | Community |
| `hasSteam()`, `steamLinkStatus()`, `scopeWhereSteamStatus()` | **No** (off User) | Identity — `SocialIdentity` query, driver-agnostic |
| `hasUsablePassword()` | Yes | Feeds the `passwordless` account state |
| `booted()` email-hash sync hook | Yes | — |

Net: LanCore's User has 30 columns, 20 relations/methods and 7 traits. The
Konventix User keeps roughly 12 columns, 2 relations (social identities,
two-factor credential) and 3 traits. Nothing on the "No" side may be
reintroduced as a relation — an arch test on cross-module model imports is the
enforcement (ADR-0004), and that test is worth writing before the model.

---

## Single-instance → multi-brand: settings that must move

LanCore had exactly one organization, so "global setting" and "operator
setting" were the same thing. Konventix has one operator and N brands. Each row
below states where the value belongs; **Ambiguous** rows are called out
explicitly because guessing wrong here is expensive.

| LanCore setting (SET id) | Operator-level | Brand-level | Notes |
|---|---|---|---|
| Organization `name` (ORG-F-001) | Yes — legal entity name | Yes — public brand name | Two genuinely different values that LanCore conflated into one. Invoices need the operator's; the header needs the brand's. |
| `logo` (ORG-F-001, ORG-F-005) | — | Yes | One logo per brand. Operator logo only appears on invoices, which Commerce renders. |
| `address_line1` / `address_line2` (ORG-F-001) | Yes | **Ambiguous** | The registered address is the operator's. A brand may want a different contact address on its imprint. Recommend operator-level with an optional per-brand override. |
| `email`, `phone`, `website` (ORG-F-001) | — | Yes | Per-brand contact details; the website is the brand's canonical domain. |
| `tax_id`, `registration_id` (ORG-F-001) | Yes | No | Tax identity belongs to the legal entity. Consumed by Commerce for invoices. |
| `legal_notice` (ORG-F-001) | **Ambiguous** | **Ambiguous** | Free text on invoices today. If it is the operator's boilerplate it is operator-level; if brands need different wording it is brand-level. Needs a legal answer, not an engineering one. |
| `impressum_content`, `impressum_responsible` | — | Yes | German TMG requires an imprint reachable from *every* domain. Each brand domain needs its own page even if the content is largely shared. Becomes a Platform CMS page, not a setting. |
| `hasImpressum` shared prop (ORG-F-003) | — | Yes | Derived from the brand's CMS pages. |
| `inertia.organization` cache key (ORG-F-004) | — | Yes | Must become brand-keyed or brands will serve each other's names. A single global key is an outright bug in a multi-brand world. |
| `default_theme_id` (THM-F-006) | **Ambiguous** | Yes | Brand-level is clearly right for the default. Whether the operator seeds a starting token set for new brands is D-IDP-05. |
| Theme library membership (THM-F-001) | **Ambiguous** | **Ambiguous** | Is a token set a shared library asset the operator curates, or a brand's private property? See D-IDP-05. |
| Per-event theme override (THM-F-004) | — | Event-level | Stays, but subordinate to the brand theme rather than to a global default. |
| Default display locale (I18N-F-001) | Yes — fallback | Yes — brand default | A German-market brand and an English-market brand under one operator must differ. |
| Enabled locale set (I18N-F-001, `SetLocale::AVAILABLE`) | Yes | — | Which translations exist is a deployment fact. |
| Experimental locale set (`SetLocale::EXPERIMENTAL`) | Yes | — | — |
| Timezone (§3.6.1) | Yes — fallback | Yes | Display timezone is per brand; per-event timezone belongs to Event. |
| bcrypt rounds (SEC-001, §3.6.1) | Yes | — | Security parameter, `.env`, never per brand. |
| Fortify feature switches (2FA on, confirm-password) | Yes | **Ambiguous** | Requiring 2FA is plausibly a per-brand policy for staff-heavy brands. Recommend operator default with per-brand tightening only. |
| Offered social login drivers | Yes — configured/credentialed | Yes — offered | Credentials are operator-level (one Steam app registration); *which* drivers a brand shows is brand-level. Identity SPEC already says this. |
| Module activation set (Pennant) | — | Yes | ADR-0003. Not a "setting" in LanCore at all — modules were simply always on. |
| Per-brand role definitions and grants (USR-F-006, ADR-0013) | Operator-level role *templates* possible | Yes | Roles are brand-scoped data via the spatie team key. |
| Required policy set (POL-F-001) | **Ambiguous** | Yes | The privacy policy is the operator's as data controller; house rules are the brand's. See D-IDP-02 and D-IDP-06. |
| Retention rules (DL-F-012) | Yes | **Ambiguous** | HGB/AO retention obligations bind the legal entity, so operator-level. But a brand-specific data category could want a shorter clock. Recommend operator-level only until a real counter-example appears. |
| Currency (`CurrencyResolver`) | Yes — fallback | Yes | Owned by Commerce; listed here because LanCore read it from global config. |
| Plausible analytics domain | — | Yes | Per-brand domain, per-brand analytics property. |
| VAPID push keys | Yes | — | One key pair per deployment. |
| Demo banner (`app.demo`) | Yes | — | Deployment mode. |
| LPPS publisher fields (ORG-F-006) | `publisher_unique_id`: **Ambiguous** | description / Steam / Discord: Yes | The LPPS federation identifier identifies a *publisher*. If each brand federates separately it is brand-level. Post-release either way. |

25 rows; 8 flagged ambiguous.

---

## Renames

| LanCore term | Konventix term | Why the rename forces a better model |
|---|---|---|
| Organization (implicit singleton) | Operator + Brand | LanCore never needed to say which one it meant, so every settings key silently means both. Splitting the word forces every one of the 25 rows above to be answered. |
| `OrganizationSetting` (key/value JSON) | `OperatorSetting` / `BrandSetting`, typed | Renaming alone does not fix the schemaless blob (lessons #11); splitting it does, because the two halves have different lifetimes, different permissions and different cache keys. |
| `Theme` | `TokenSet` (brand theme) | "Theme" in LanCore means a bag of raw shadcn CSS variables. Calling it a token set forces the ADR-0008 vocabulary — semantic tokens with contrast validation — instead of letting anyone drop `--primary: #ff0000` into a JSON column. |
| `PaletteVariables` | semantic token vocabulary | Same reason, one level down: a *palette* is values, a *vocabulary* is meaning. Components may bind to the second and not the first. |
| `username` | `handle` | Frees the word "user name" from meaning two different things (`name` vs `username`, a genuine source of confusion in LanCore's own SRS and in ICLIB-F-010's warning to satellites). |
| `name` (real name on User) | `legal_name` on the invoice recipient | Naming it *legal* name makes it obvious it is a billing artifact, which makes it obvious it does not belong on User. |
| `PolicyAcceptance` | `ConsentRecord` | Acceptance implies a user pressing a button on a document. Consent is the legal concept and covers cookie consent, photo consent and marketing consent — none of which have a "policy version" in LanCore, which is why `cookie_preferences` ended up as an unregulated JSON blob on the users table. |
| `DeletionRequest` | `SubjectRequest` (typed export / deletion / rectification) | LanCore has a first-class entity for deletion and an artisan command for export, so exports have no state, no deadline and no audit. One entity with a `type` makes the Art. 15 and Art. 17 paths structurally identical. |
| `GdprDataSource` | `GdprExportHandler` | "Data source" reads like a read model Compliance may query. "Handler" says Compliance sends it a message and it answers — which is the ADR-0004 rule. |
| `DomainAnonymizer` | `GdprErasureHandler` | Pairs with the above and stops presupposing that anonymize is the only mode (`erase` is the other). |
| `RetentionPolicy` | `RetentionRule` | "Policy" already means two things in this codebase (legal document, Laravel authorization class). A third meaning inside the same module is untenable. |
| `DataLifecycle` (domain) | Retention (area within Compliance) | It was never a domain — it was Compliance's erasure and retention half, plus an anonymizer registry that reached into twelve other domains. Demoting it to an area inside Compliance makes the fan-out the only allowed mechanism. |
| `RoleName` enum | role rows | The enum *is* the deploy requirement (lessons #9). Deleting the type is the change; the rename is just the visible part. |
| `Auth\Steam` | `SocialProvider` driver | A namespace named after a vendor cannot hold a second vendor. Renaming to the contract forces Steam to be constructed as one implementation, and the Identity TODO already demands a non-gaming driver ship alongside it to prove the abstraction is not Steam-shaped. |
| `ResolveEventTheme` middleware | `ResolveBrandTheme` | The event was the top of the resolution chain in LanCore. In Konventix the brand is, and the event is an override — the middleware name should state the new precedence, not the old one. |
| `organization` shared prop | `brand` shared prop | Every Vue component reading `$page.props.organization` is reading brand data. Renaming the prop is how you find all of them. |

---

## Dropped / deferred, and why

**Dropped outright:**

- **`RolePermissionMap` and the `RoleName` enum** (USR-F-006, USR-F-015).
  Superseded by ADR-0013. The capability enums survive; the role→permission
  mapping becomes per-brand rows.
- **`HasPermissions` trait** (USR-F-016). Replaced by
  spatie/laravel-permission, which already does request caching and Gate
  integration.
- **Permissions as a flat array in shared Inertia props** (USR-F-018,
  SEC-013). Directly named in lessons #5. The frontend gets per-page
  authorization booleans from the page's `Data` object instead. `usePermissions()`
  survives in a form generated from the enums (ADR-0009), not hand-written.
- **The central `GdprServiceProvider::SOURCES` array.** ADR-0003 forbids it;
  each module registers its own handler from its own provider.
- **The 408-line `AppServiceProvider` wiring and the 1,285-line sidebar.** Not
  requirements, but they are what USR-F-020 and SEC-012 currently mean in
  practice; both become Platform registries.
- **`Gate::before()` superadmin bypass as the design** (USR-F-017, SEC-012).
  An unlogged, unscoped god mode is incompatible with a per-brand audit trail.
  The *capability* survives as an explicit operator-level permission; the
  silent global short-circuit does not.
- **`profile_updated_at`** (USR-F-024). It exists only to diff webhook
  payloads; Extensibility is post-release and will decide its own change-tracking.

**Deferred post-release:**

- **LPPS publishing fields** (ORG-F-006). Content/Publishing module, and the
  federation standard is not a release blocker.
- **Public profile, banner, bio, emoji, achievements on profile**
  (USR-F-023..026). These are Community, which the module map defers. Identity
  must still ship `handle` and `avatar_url` at release because Registration and
  Admission display them.
- **`ICLIB-F-*` client library and the satellite `LanCoreUser` DTO.** The whole
  out-of-process tier is Extensibility; overview.md's non-goals are explicit
  that no external contract ships before the internal ones are stable.
- **Session revocation UI.** Identity's SPEC claims it, LanCore never had it,
  and nothing at release depends on it. The `sessions` GDPR handler ships;
  the management screen can follow.

**Explicitly not ours** (listed so nobody claims them twice): seating privacy
(SET-F-009/010 → Resources), notification preferences and delivery
(→ Messaging), newsletter subscription state (NLT-F-003 → Newsletter),
ticket discovery (USR-F-008 → Registration), checkout-condition
acknowledgement (SHP-F-010/011 → Commerce, deliberately separate from Policy
in LanCore too).

---

## Open decisions

### D-IDP-01: Is an account global across the operator's brands, or per brand?

**Context:** LanCore had one instance, one `users` table, one email-unique
constraint — the question could not arise. Konventix has one operator (one
legal entity, ADR-0001) running N brands on N domains, with brand-scoped
sessions either way. The answer decides whether one human visiting two brand
domains is one `users` row or two, what `UserRegistered.brand_id` means, and
whether Compliance's subject requests span the operator or stop at a brand.
It is flagged as ADR-worthy in Identity's SPEC and Registration depends on it.

**Option A — Global account, per-brand membership.** One `users` row per human
across the operator. Email is unique operator-wide. A `brand_memberships` row
records first-seen brand, per-brand roles (already how ADR-0013's team key
works), and per-brand preferences. Sessions stay brand-scoped.
*Pros:* One subject, one deletion clock, one export — the GDPR fan-out is
coherent. Cross-event/cross-brand community (an explicitly stated
differentiator in `overview.md`) becomes possible at all. Single sign-on across
brand domains is a product feature, not an integration project. Handle
uniqueness is trivially defined.
*Cons:* Signup on brand B can reveal that an address already exists on brand A
unless every "email taken" path is made indistinguishable (always "check your
inbox", never a validation error). Brands that want to look like independent
businesses leak a shared identity. Migrating later to per-brand is effectively
impossible.

**Option B — Per-brand account.** `(brand_id, email)` unique. The same human is
N rows.
*Pros:* Maximum brand separation; a brand can be sold or spun out cleanly.
No cross-brand enumeration risk. Matches naive intuition about "separate
websites".
*Cons:* One human is N data subjects with N deletion clocks over data
(orders, invoices, audit rows) held by *one* controller. An Art. 17 request
filed on brand A cannot legally stop at brand A, but there is no supported join
to reach brand B's rows. Community is impossible. Handle uniqueness is either
per-brand (so `@markus` is two people) or global (so accounts are not really
separate). Every module's read model needs a composite key.

**Option C — Global account with per-brand visibility.** One row, but a brand
only "sees" users who have interacted with it; admin lists, search and exports
are brand-filtered by default.
*Pros:* Option A's data model with most of B's operational separation.
*Cons:* The visibility rule is a cross-cutting scope every module must respect,
and getting it wrong is a privacy incident rather than a bug. It is Option A
plus a policy, so it can be adopted later without a migration.

**Recommendation: Option A, with Option C's brand-filtered admin views layered
on from the start.** The decisive reason is Compliance, not convenience: the
operator is a single legal entity and therefore a single data controller, so an
Art. 17 erasure request is owed against *all* of the operator's processing of
that person regardless of which brand it was filed on. Option B makes the
correct behaviour unimplementable — Compliance would have to join across
`user_id` values it has no supported way to relate — and per LanCore's own
`RetentionDataClass` the data in question (orders, audit rows) is held for ten
years under HGB §257. Choosing B means shipping a known-unfulfillable legal
obligation. Write the ADR before Registration starts.

### D-IDP-02: Is consent scoped to the brand it was given on, or to the operator?

**Context:** LanCore has one `policy_acceptances` table, no brand column, and
`Policy` rows that are operator-wide. Konventix must decide whether accepting
the privacy policy on brand A means anything on brand B. Depends on D-IDP-01.

**Option A — All consent is brand-scoped.** Every `Policy` carries a
`brand_id`; a subject re-accepts on each brand.
*Pros:* Unambiguous, matches "each brand is its own public identity". A brand
can publish house rules nobody else has. The `RequirePolicyAcceptance` gate
stays trivially correct.
*Cons:* A person on three brands accepts three near-identical privacy policies.
If the operator is the controller, that is legally redundant and reads as dark
patterning.

**Option B — All consent is operator-scoped.** Policies live at the operator;
brands render them.
*Pros:* One acceptance, one withdrawal, minimal friction. Matches the single
controller.
*Cons:* Brand-specific rules (photo consent at brand A's events, brand B's
house rules) have nowhere to live. A withdrawal on one brand silently changes
another brand's gate.

**Option C — Policy carries its own scope.** A `Policy` is declared either
`operator` or `brand` scoped at creation; the privacy policy and terms are
operator-scoped, house rules and photo consent are brand-scoped. Consent
records store the scope they were captured at.
*Pros:* Both real cases are expressible. The re-acceptance gate resolves
"required policies for this brand" as operator-scoped ∪ brand-scoped.
*Cons:* One extra concept in the model and one extra branch in the gate query.
Operators can pick the wrong scope, and moving a policy between scopes after
consents exist is not a supported operation.

**Recommendation: Option C.** The cost is one enum column and a slightly larger
gate query; the alternative is discovering mid-release that photo consent has
nowhere to live (Option B) or that users are being asked to accept the same
privacy policy three times (Option A). Make the scope immutable after the first
version is published, and record it on every consent row so the export can
state what the subject actually agreed to.

### D-IDP-03: How is the audit trail built — wildcard event subscription or explicit recorder?

**Context:** LanCore uses `owen-it/laravel-auditing` model traits on 124
models, producing attribute-diff rows in one `audits` table, plus 13 per-entity
audit viewer controllers. Compliance's SPEC proposes a wildcard subscription
over events carrying an auditable marker. ADR-0004 pushes hard that way, but
some auditable actions (viewing a participant list, exporting a CSV, an admin
impersonating a user) have no natural domain event.

**Option A — Keep model-level auditing (`owen-it` traits).** Every module puts
the trait on its models.
*Pros:* Zero design work, proven in LanCore, catches every write including ones
nobody remembered to emit an event for. `$auditExclude` already handles secrets.
*Cons:* Records *attribute diffs*, not *actions* — you learn `status` changed
from `pending` to `confirmed`, never that it happened via the refund flow.
Silent on reads. Couples Compliance to an ORM concern in every other module,
which is exactly the coupling ADR-0004 exists to prevent. 124 models × strict
mode is also a real write-amplification cost.

**Option B — Wildcard subscription over auditable domain events.** Compliance
subscribes with `Event::listen('*')` (or an `Auditable` marker interface) and
appends actor/action/subject/brand rows.
*Pros:* Records intent, not diffs. Zero coupling — modules emit their normal
public contract and Compliance decides what is worth keeping. Naturally
brand-scoped because event payloads carry `brand_id`.
*Cons:* Audit completeness becomes a property of event coverage: an action with
no event is invisible, and nothing fails when someone forgets. Reads are still
unrecorded. A wildcard listener on every dispatched event is a hot-path cost
that must be measured.

**Option C — Explicit recorder contract modules call.** Compliance exposes
`AuditRecorder::record(action, subject, context)`; modules call it deliberately.
*Pros:* Covers reads and exports. Completeness is visible in review — the call
is either there or it is not.
*Cons:* Every module now depends on a Compliance service, which is the
inverted-dependency shape ADR-0004 forbids for state. Easiest of the three to
forget silently.

**Option D — B as the mechanism, plus a narrow `AuditableAccess` event modules
emit for read-type actions.** The recorder is still an event; reads just get
their own event type.
*Pros:* One mechanism, one subscription, no service dependency, and the read
gap is closed by a documented event rather than a second pathway.
*Cons:* Modules must remember to emit it — same discipline problem as C, but at
least the failure mode is uniform.

**Recommendation: Option D.** Take B's mechanism because ADR-0004 is the whole
reason this architecture exists, and close its one real gap (reads and exports)
with a dedicated event rather than a second, differently-shaped API. Measure
the wildcard listener cost early and queue the append if it shows up; make the
`audits` table append-only at the database level. Do *not* also keep the
`owen-it` traits — two audit trails that disagree are worse than one that is
incomplete.

### D-IDP-04: Where does role assignment live — Platform or Identity?

**Context:** Assigning a role touches a brand role (Platform) and a user
(Identity), and neither module may import the other's model (ADR-0004). LanCore
had it in `UserController::bulkAssignRole` on a User-owning controller, which
is not available here. Flagged as an open question in both SPECs.

**Option A — Platform owns it.** Platform's role admin screen takes a user
ULID; Identity exposes a read-only user lookup endpoint (ULID, handle,
display name) for the picker.
*Pros:* Grants live with the thing being granted. Platform already owns
`permissions:sync`, `RoleGrantsChanged`, and the spatie team context. One place
to audit "who can do what on this brand".
*Cons:* Platform needs a user picker, which means a thin Identity read model or
a lookup contract — the first foreign-data dependency in a module whose SPEC
says it consumes nothing by design.

**Option B — Identity owns it.** The user detail screen has a roles section;
Identity emits `RoleAssignmentRequested` and Platform applies it.
*Pros:* Matches admin intuition ("open the person, change their access"). No
picker needed. Identity already needs the user administration screens.
*Cons:* Identity must know the brand's role list — a Platform read model inside
Identity, which is precisely the growth Identity's SPEC says signals the god
object returning. An async assignment also has an awkward UX (the page cannot
show the result of the write it just made).

**Option C — Platform owns the write; both modules render an entry point.**
The action, the route and the authorization are Platform's
(`ManageRoles`). Identity's user detail page renders a Platform-provided UI slot
that links to (or embeds) it. Platform's role page lists members by ULID and
resolves display data from a small Identity-fed read model updated by
`UserRegistered` / `UserProfileUpdated`.
*Pros:* One writer, one authorization check, one audit event. The slot registry
already exists for exactly this. Both admin journeys work.
*Cons:* Platform maintains a read model, contradicting its "consumes: none by
design" line — that line needs amending, and this is a real cost.

**Recommendation: Option C, and amend Platform's SPEC.** The "Platform consumes
nothing" rule is a good instinct about *state*, but a display-only projection of
handle and display name is not state — it is the same read-model pattern every
other module uses, and refusing it here just relocates the coupling somewhere
worse. Keep the projection to three columns (ULID, handle, display name) and
rebuild it from events so it is never authoritative.

### D-IDP-05: What is the scope of a theme / token set?

**Context:** LanCore's `Theme` is a global library row; events point at one, and
an org-wide default fills the gap (THM-F-004..006). Konventix is brand-first.
The question is who owns a token set and who may edit it.

**Option A — Token sets belong to brands; each brand has exactly one.**
`brands.token_set` is a column-ish owned value; events may not override.
*Pros:* Simplest possible model. Brand identity is by definition one look.
Contrast validation runs on one object.
*Cons:* Loses THM-F-004 outright — LanCore genuinely used per-event themes
(a summer event styled differently from a winter one), so this is a feature
regression with a real user behind it.

**Option B — Token sets are an operator-curated library; brands and events
select from it.** Closest to LanCore.
*Pros:* Preserves both THM-F-004 and THM-F-006. Contrast validation runs once
per set, not per brand. Operators reuse a validated set across brands.
*Cons:* A brand cannot self-serve its own look without the operator, which is
backwards for a *brand*. In a hosted multi-operator future it is actively wrong.

**Option C — Brand owns its token sets; one is the brand default; an event may
select another of the same brand's sets.**
*Pros:* Keeps per-event theming, makes the brand self-sufficient, and the
resolution chain (event override → brand default → platform default) mirrors
LanCore's shape with the top two entries swapped. Contrast validation is a
brand-scoped operation gated by `ManageBrandTheme`.
*Cons:* Duplication across brands that want the same look; needs a "copy from
brand" affordance. Operator-level seed defaults for new brands become a
separate small question (Platform's own open question #5).

**Recommendation: Option C.** It is the only option that keeps the one theming
feature LanCore actually shipped (per-event override) while making the brand —
not the operator — the unit of design ownership, which is what ADR-0001 and
ADR-0008 both imply. Seed a new brand by copying a built-in default set rather
than by inheriting a live operator set, so no operator edit can silently
restyle a live brand.

### D-IDP-06: How does LanCore's Policy domain map onto Compliance?

**Context:** LanCore's Policy domain is the most complete thing in this scope:
`PolicyType` → `Policy` (stable `key`) → `PolicyVersion` (linear per
`(policy, locale)`, PDF snapshot, editorial flag) → `PolicyAcceptance` (locale,
IP, UA, source enum, withdrawal). Compliance's SPEC describes "policies and
their versions" plus "consent records" without committing to that structure.

**Option A — Port the four-entity model verbatim, add `brand_id` and swap
`user_id` for `subject_id`.**
*Pros:* It works; POL-F-001..018 are a coherent, tested design and the
editorial/non-editorial distinction (POL-F-008) is genuinely well-judged — it is
the difference between "we fixed a typo" and "you must re-accept". The PDF
snapshot is real evidence under Art. 7(1). Fastest path.
*Cons:* Inherits `cookie_preferences` having nowhere to go: LanCore's consent
model only handles *documents*, so cookie and photo consent stayed as an
untyped JSON blob on `users`. Porting as-is reproduces that hole.

**Option B — Generalize to a `ConsentSubject` model: consent is given to a
*thing* that may be a policy version, a processing purpose (cookies,
analytics), or an event-specific permission (photography).**
*Pros:* One consent table, one withdrawal path, one export section covering
every kind of consent. Closes the `cookie_preferences` hole by construction.
*Cons:* Substantially more design. The document-shaped features (version
numbers, PDF, re-acceptance gate) only apply to one of the kinds, so the model
grows optional branches. Over-abstraction risk before the second real case
exists.

**Option C — A as the base, with consent targets polymorphic from day one.**
Keep `PolicyType`/`Policy`/`PolicyVersion` exactly as LanCore has them, but make
`ConsentRecord` reference a `consent_target` (type + ULID) rather than a
`policy_version_id`, and ship exactly two target types at release:
`policy_version` and `processing_purpose`.
*Pros:* All of LanCore's proven document machinery survives untouched.
Cookie and photo consent land in the same table with the same withdrawal and
the same export, without inventing versioning for things that have no versions.
*Cons:* Polymorphic reference costs an index and a resolver. Two target types
at release means one of them (`processing_purpose`) is thin and may look
over-engineered until photo consent arrives.

**Recommendation: Option C.** LanCore's Policy domain is one of the few places
where copying is the right call — do not redesign it. The single change worth
making is the consent *target*, because `cookie_preferences` sitting as an
unregulated JSON blob on the users table is a concrete, already-observed failure
of the document-only model, and it is far cheaper to make the reference
polymorphic now than to migrate consent records later.

### D-IDP-07: What identity does an account-less subject use?

**Context:** ADR-0005 separates Registration from Admission and allows an
Admission to exist without a user account, so a person can hold personal data in
the system with no `users` row. Compliance's `subject_id` must address them, and
they must be able to file and authenticate a subject request. Open in both SPECs.

**Option A — Compliance issues a `Subject` ULID for anyone whose personal data
is stored; a `users` row links to its subject rather than being one.**
*Pros:* One subject type for the whole fan-out; module handlers take one kind of
ID. Guest and account holders are handled identically, including if a guest later
registers (the subject is relinked, the data stays reachable).
*Cons:* One extra indirection on every handler call, and Identity must emit
subject linkage on registration. Merging a guest subject into an account subject
is a real operation someone must design.

**Option B — Subject ID is the user ULID when one exists, and the Registration
ULID otherwise.** Handlers accept either.
*Pros:* No new entity.
*Cons:* Every handler branches on subject kind, forever. A guest with three
registrations is three subjects. Precisely the composite-key mess that D-IDP-01
Option B produces, arriving through a different door.

**Option C — Guests get no subject request self-service; requests are handled
manually by an operator against an email address.**
*Pros:* Zero build. Legally defensible at small volume — Art. 15/17 require the
right, not a self-service UI.
*Cons:* Manual handling against a statutory deadline scales badly and is exactly
what the fan-out was built to automate. Also leaves the identity question
unanswered rather than answering it.

**Recommendation: Option A**, with authentication by an emailed one-time link to
the address on file (the same shape as the existing deletion-confirm token,
DL-F-001/003, which already authenticates by email rather than by session). It is
the only option where a module's `Gdpr/` handler has exactly one kind of ID to
understand, and the fan-out's whole value proposition is that handlers are
simple.

### D-IDP-08: Anonymize or erase by default, and does a tombstone row survive?

**Context:** LanCore anonymizes in place (DL-F-009: name → `Deleted User #hex`,
email → `deleted-<id>@anonymized.invalid`, PII cleared, password randomized) and
keeps the soft-deleted row, hard-deleting it only when every retention evaluator
agrees (DL-F-013). Compliance's SPEC has a `mode` of `anonymize` or `erase`
without a default, and Identity asks whether a tombstone survives so foreign
ULIDs stay resolvable.

**Option A — Anonymize by default, tombstone row retained, hard delete only
after all retention clocks expire.** LanCore's behaviour.
*Pros:* Proven. Foreign ULID references (orders, audit rows, competition
results) stay resolvable, so no module needs to tolerate dangling IDs.
Satisfies HGB §257 without a special case. Reversible-adjacent: the export
command still finds the subject via `email_hash` (DL-F-009/CAP-DL-007), so
post-deletion subject access requests remain serviceable.
*Cons:* "Deleted" does not mean deleted, which requires clear communication.
The tombstone is still a row about a person.

**Option B — Erase by default, modules tolerate dangling ULIDs.**
*Pros:* Cleanest reading of Art. 17. Nothing about the person remains.
*Cons:* Ten-year bookkeeping retention makes it unachievable for anyone who ever
bought anything — which, on an event platform, is most subjects. Every module
must handle "referenced ULID does not resolve", forever, in exchange for a
guarantee it cannot actually give.

**Option C — Mode chosen per request by the handling operator.**
*Pros:* Flexible; an operator can honour an unusually strong erasure demand.
*Cons:* Makes an operator decide a legal question per request, and modules still
need Option B's dangling-ID tolerance for the erase path. Worst of both.

**Recommendation: Option A.** Anonymize by default, keep the tombstone, hard
delete when retention allows, and keep `erase` in the enum but reachable only
through the audited force-delete path (DL-F-015's mandatory-reason requirement).
The decisive point is that dangling foreign ULIDs are a permanent tax on all 18
modules, and Option A avoids it while being the *more* legally defensible
position, not the less — retention obligations are a lawful basis for keeping
the minimum, and LanCore already encodes which categories those are.

### D-IDP-09: Where do a person's legal name and address live?

**Context:** LanCore puts `name`, `phone`, `street`, `city`, `zip_code`,
`country` on `users` and carves them out of public responses with SEC-021.
The Identity SPEC says they move to Commerce as "invoice recipient data on the
Registration that needed them". That is the direction, but not yet a mechanism.

**Option A — Commerce owns an `InvoiceRecipient` per order/registration;
nothing persists between purchases.**
*Pros:* Strictly correct — the address that mattered is the one at the time of
invoice, which is also what bookkeeping retention wants frozen.
*Cons:* A returning buyer retypes their address every time, which is a
noticeable regression against LanCore.

**Option B — Commerce owns a reusable `BillingProfile` keyed by user ULID, and
snapshots it onto each invoice.**
*Pros:* Convenience preserved, invoice immutability preserved. Address stays out
of Identity. One module owns the GDPR handler for it.
*Cons:* A profile keyed by user ULID looks a lot like a User relation wearing a
hat; the discipline has to be that Identity never reads it.

**Option C — Registration collects it per registration; Commerce reads the
registration.**
*Pros:* Matches the fact that a registration often collects a shipping/badge
address anyway.
*Cons:* Puts billing data in the module that ADR-0005 defines as the commercial
act, not the settlement, and splits the GDPR handler awkwardly across two
modules.

**Recommendation: Option B.** It preserves the user-visible behaviour LanCore
had, keeps the invoice snapshot immutable for HGB retention, and puts exactly
one module in charge of the export and erasure handler for the most sensitive
non-auth data in the system. The rule to write down alongside it: the billing
profile is addressed by user ULID and is never joined to `users` in a query.

### D-IDP-10: Typed settings objects, or a key/value table?

**Context:** LanCore's `OrganizationSetting` is `key` → JSON `value`, read with
magic strings from eight places, with no validation beyond the one controller
that writes it (lessons #11, ADR-0009). The multi-brand split doubles the
surface.

**Option A — Typed settings classes per area (`BrandIdentitySettings`,
`BrandThemeSettings`, `OperatorLegalSettings`), persisted as casted columns or
a per-area JSON column with a `Data` object.**
*Pros:* Validation, TS types and Inertia props from one source (ADR-0009).
Autocomplete and refactoring work. Impossible to typo a key.
*Cons:* Adding a setting is a migration or at least a class change. Modules that
want their own brand-level setting need a registration mechanism.

**Option B — Keep key/value, add a registry where each module declares its keys
with a type and default.**
*Pros:* Modules add settings without touching Platform. No migration per setting.
*Cons:* Still stringly-typed at the call site unless every module also writes a
typed accessor, i.e. Option A's work with extra indirection.

**Option C — Typed classes for Platform's own settings (identity, theme, legal,
locale) plus a registered key/value area for module-contributed settings.**
*Pros:* The settings that everything reads are typed and validated; module
settings stay extensible. Matches how navigation and Pennant features already
work — Platform owns the registry, modules contribute.
*Cons:* Two mechanisms; contributors must know which to use.

**Recommendation: Option C.** The dozen settings Platform reads on every request
(brand name, logo, locale, theme, imprint presence) deserve types because they
are on the hot path and in the shared props. Module-contributed settings are
rarer, later, and genuinely open-ended; forcing them through a migration would
push modules back toward central wiring, which ADR-0003 forbids.

### D-IDP-11: Fortify, or hand-rolled authentication?

**Context:** LanCore uses Laravel Fortify (USR-F-001..005, SEC-002), and
Konventix's Identity module already contains Fortify-shaped request classes
(`TwoFactorAuthenticationRequest`, `PasswordUpdateRequest`) and a
`ResetUserPassword` action. But brand-scoped sessions, per-brand social driver
sets and a `pending`/`locked` account state are not things Fortify models.

**Option A — Keep Fortify, wrap it.** Custom actions where Fortify allows them,
brand context applied by middleware around Fortify's routes.
*Pros:* Password reset, email verification, 2FA enrolment and recovery codes are
security-critical and already correct. Zero migration friction from LanCore.
The existing module code already assumes it.
*Cons:* Fortify owns its routes and views, which fights the module's
route-registration rule (`routes/web/identity.php` registered by the provider).
Account states beyond authenticated/not are bolted on via middleware. Its
responses are not `Data`-object shaped (ADR-0009).

**Option B — Hand-rolled auth on Laravel's primitives.** Own the controllers,
own the routes, use Laravel's guards, hashing, and signed URLs directly.
*Pros:* Full control over brand scoping, account state, `Data` objects and event
emission. Every route obeys the module conventions.
*Cons:* Owning password reset and TOTP enrolment is owning security-critical
code for a 1–3 person team. This is exactly the reasoning ADR-0013 used to
choose a library over a first-party permission system.

**Option C — Fortify for the credential mechanics (hashing, reset tokens, TOTP,
recovery codes) with Identity owning all routes and controllers.** Use Fortify's
actions and contracts, register none of its routes.
*Pros:* The hard cryptographic parts stay borrowed; the HTTP surface is the
module's, so brand scoping, `Data` objects and events are all natural.
*Cons:* Slightly against the grain of the package; some Fortify features
(its views, its route model) go unused, so the dependency looks heavier than
what is consumed.

**Recommendation: Option C**, and it is consistent with ADR-0013's precedent:
borrow the hardened mechanism, own the surface. The specific trigger is
brand-scoped sessions and the `pending`/`locked`/`anonymized` account state —
both need to sit *inside* the login pipeline, and Fortify's route layer is the
wrong place to put them.

### D-IDP-12: Who owns the handle, and how unique is it?

**Context:** LanCore's `username` is globally unique case-insensitively, is the
route key for `/u/{username}`, and is the sole public display field per
ICLIB-F-010. Konventix splits Identity (account) from Community (public
profile), and Community is deferred post-release — but Registration and
Admission need to display *something* at release.

**Option A — Handle on Identity, unique operator-wide, required at signup.**
*Pros:* One namespace, no collisions, resolvable from any brand. Available at
release without Community. Matches D-IDP-01 Option A.
*Cons:* Handles are a scarce global resource across brands that may have nothing
to do with each other; brand B's users compete with brand A's for names.

**Option B — Handle per brand.** Unique within `(brand_id, handle)`.
*Pros:* No cross-brand scarcity.
*Cons:* If accounts are global (D-IDP-01), one account has N handles, and
`@markus` means different people on different brands — a phishing surface in any
shared surface (chat, mentions) and a mess for Community later.

**Option C — Handle optional at signup, required only when a module needs one
(public profile, chat, competition brackets).**
*Pros:* Lower signup friction, which matters for an event platform where most
users register once to buy a ticket. Avoids LanCore's `RequireUsername`
retro-onboarding middleware existing at all.
*Cons:* Every display surface needs a fallback (LanCore's `Player #{id}`
placeholder, ICLIB-F-002). Handle can become required later, which is a second
onboarding gate.

**Recommendation: Option A.** Global uniqueness is the only choice that composes
with a global account, and requiring it at signup avoids reproducing LanCore's
`RequireUsername` middleware — which exists purely because the field was added
after the fact. The scarcity objection is theoretical at the scale of one
operator's brands; the phishing objection to Option B is not.

### D-IDP-13: Who watches the statutory deadline on subject requests?

**Context:** GDPR gives one month to answer an Art. 15 or Art. 17 request.
LanCore has no deadline concept at all — export is an artisan command, and the
deletion flow's 30-day grace is a *user* protection, not a regulatory clock.
Compliance's SPEC lists the deadline as a field on the request but leaves the
escalation open.

**Option A — Operator-facing queue only.** The request list shows the deadline
and sorts by urgency; nobody is pushed.
*Pros:* Nothing to build beyond a sort order. No mail volume.
*Cons:* Depends on someone opening the page. For a volunteer-run operator (the
initial user is a Verein), that is a realistic failure mode with a regulatory
consequence.

**Option B — Compliance scheduler emits reminders through Messaging at fixed
intervals (T-14, T-7, T-2 days) to `HandleSubjectRequests` holders.**
*Pros:* Deadline misses require ignoring three notifications. Reuses Messaging;
no new infrastructure.
*Cons:* Notification fatigue if request volume is ever high. Needs an
acknowledged/snoozed state or it nags after work has started.

**Option C — Auto-fulfil the export path.** Export requests are fully automated
(the fan-out already produces the archive), so only deletions and rectifications
reach a human queue at all.
*Pros:* Removes most of the volume from the deadline problem entirely. An
automated export is also a better subject experience.
*Cons:* Requires the subject to be authenticated well enough to trust the
automation (see D-IDP-07). Deletion still needs B.

**Recommendation: C plus B.** Automate export end-to-end — the machinery already
exists and a human in that loop adds risk rather than safety — and put the
remaining deletion/rectification requests behind escalating reminders with an
acknowledge action. Option A alone is a compliance risk disguised as a UI
decision.
