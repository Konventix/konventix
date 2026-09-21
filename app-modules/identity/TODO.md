# Identity — TODO

> Living document. Update it in the same PR that changes the code: check off
> what you completed, add follow-ups you discovered. Do not generate status
> dashboards, percentages, or summary tables here.

## Next

Phase 1 needs only enough of Identity for a registration flow to have a logged-in
person behind it. The value here is in what is left out.

- [x] Extend the User migration with the remaining slim account fields
      (locale, timezone, account state, typed preferences) — the model and
      ULID key exist, the columns beyond name/email/password do not.
- [x] `Data` objects for every auth form so validation, Inertia props and the
      generated TypeScript types come from one place (ADR-0009). The pipeline
      exists now (`composer types:generate`); the auth forms still use classic
      FormRequests.
      Done for register, profile update, and password update
      (`RegisterData`, `ProfileUpdateData`, `PasswordUpdateData`).
      `ProfileDeleteRequest` and `TwoFactorAuthenticationRequest` are left as
      FormRequests — a delete confirmation and a stateless 2FA-state gate are
      not really "forms" in the ADR-0009 sense, and Fortify's own contract for
      the latter (`InteractsWithTwoFactorState`) expects a FormRequest.
- [x] Minimal shared Inertia props: ULID, display name, avatar URL, locale.
      Nothing computed, nothing eager-loaded, no permission map
      (lessons-from-lancore #5).
      Done with slice 25 (v0.4.0): `auth.user` in
      `App\Http\Middleware\HandleInertiaRequests` (host-level, out of this
      module's file scope) shares exactly the promised fields, the avatar URL
      resolved through `AvatarUrlResolver`. Five since #243, which added
      `timezone`.

## Backlog

- [x] `SocialProvider` driver contract plus a `social_identities` table.
      Steam is the only driver (D-IDN-01 as amended 2026-08-19 — Google and
      Discord dropped, not deferred), so the "prove it is not Steam-shaped"
      half of this item could not be discharged the way it was written. The
      contract is instead modelled on the *hardest* case: it returns an
      identity rather than a user, its email is nullable because Steam asserts
      none, and it carries no notion of scopes or tokens. An OAuth2 driver fits
      inside that without widening it. The registry is also the route's
      whitelist, so an unregistered `{provider}` 404s before reaching Socialite.

      Worth knowing, because it looks arbitrary otherwise: `users.password` is
      now nullable (a Steam-first account genuinely has none, and a placeholder
      hash would be a lie every later check had to decode), and the callback
      decides the signed-in case *before* the identity-known case — otherwise
      connecting somebody else's Steam account silently switches your session
      into theirs.

- [ ] Unlinking a provider is not re-authenticated. `password.confirm` was the
      obvious gate and is the wrong one: a Steam-only account has no password
      to confirm, so the middleware would dead-end the screen for precisely the
      accounts social login creates. What protects the account today is the
      lockout guard — the last way in cannot be removed at all. The right
      confirmation is a fresh round-trip through the provider, which needs a
      "re-authenticate, do not link" mode on the callback.

- [ ] No preview of what a social signup will be called before it exists: the
      display name is asked for, and a deployment with no Steam Web API key has
      no suggestion to prefill it with. Harmless, but the reason the field
      looks empty in dev is worth knowing.

- [ ] A social signup naming an email that already exists is refused with an
      ordinary "already taken" error rather than being offered a link. That is
      deliberate — Steam asserts no address, so linking on a typed match would
      be account takeover by typing — but it inherits the ADR-0016 disclosure
      gap recorded below: the refusal reveals the address is in use. Both are
      closed by the same fix.
- [x] Two-factor enrolment, recovery codes, and the active session list with
      revocation. `Features::twoFactorAuthentication(['confirm' => true,
      'confirmPassword' => true])`; enrolment/confirm/disable/recovery-codes
      run through Fortify's own routes and controllers, this module supplies
      the `User` traits, the view callbacks, and the settings-page UI
      (`TwoFactorAuthentication.vue`). Administrative reset (an operator
      resetting *someone else's* 2FA) is not built — that needs the
      capability catalogue below first, since it is not a self-service action.
- [ ] Capability catalogue in `src/Enums/Permission.php`, policies for user
      administration, and the user administration screens registered with
      Platform's navigation registry.
- [x] `src/Gdpr/IdentityGdprHandler`: exports the account, brand memberships
      and session history; anonymizes with a tombstone and emits
      `UserAnonymized`. `AccountState::Anonymized` landed with it and
      `AuthenticateUser` refuses it. Social identities join the export when
      social login lands (slice 15).
      Deletion is now operator-wide (ADR-0050), so the handler deletes all
      memberships and anonymizes once, not conditionally per brand.
      Slice 25 tail (ADR-0051 §9): the four profile-of-record fields
      (`username`, `tagline`, `status_emoji`, `social_links`) join the export
      and are tombstoned with the account — `username` becomes
      `deleted-<ulid>`, unique and meaningless because the column is NOT NULL
      and the freed handle must not resolve to someone else's page. The
      memberships map carries `avatar_media_id`/`banner_media_id`, and a
      deletion releases their `MediaUsage` rows before the membership rows go.
      `MediaLibrary` is resolved at call time, never captured at boot
      (.ai/rules/providers.md). `tests/SocialIdentityTest.php` (Task 10 cases)
      and Compliance's `GdprSweepTest`.
- [ ] ADR-0054 §6, still open (was called "GitLab #18, Phase 2" while the
      enumeration gap below was open too — that half is now closed, this one
      is not): the notice mail a duplicate registration/signup sends
      (`identity.registration-attempt`, below) does not yet name the
      operator or the brand the account was first used on — it is where a
      person with two brands would learn they have one account. The sign-up
      page half of this point is done (#241, `OperatorPresenceNotice`); the
      mail half is what remains.
- [ ] "Leave brand B, keep the account" has no path (ADR-0054 consequences):
      deletion spans the operator (ADR-0050), and nothing removes a single
      membership with its brand-scoped rows. Not a go-live concern; design
      it when a person asks for it.
- [ ] The session list on the Security page has no way to scope to "this
      brand's sessions" — the `sessions` table carries no `brand_id` column
      (Laravel's own table), so `Queries\ActiveSessionsQuery` lists every
      session for the account across every brand it has logged into. The UI
      copy says "sessions on this brand" per the task brief that added it, but
      nothing enforces that today. Revisit if a brand-scoped list turns out to
      matter (e.g. a `brand_id` column populated from `BrandContext` when the
      row is written, or filtering by decoding `payload`).
- [ ] `Actions\AuthenticateUser` (the `Fortify::authenticateUsing` override
      that enforces `AccountState::Locked`) bypasses
      `EloquentUserProvider::rehashPasswordIfRequired()`, so a login through
      this path never upgrades an old password hash. Low priority — the
      default provider already covers `Features::resetPasswords()` and manual
      password changes.

- [ ] Cross-module data move for `AccountsMerged` (#81): Registration,
      Commerce, Admission and Compliance each need a listener that
      reassigns their own owned rows (registrations, orders, admissions,
      consent records) from `merged_user_id` to `surviving_user_id` when
      this event fires. None exists yet, so a merged account's history stays
      on the closed account today — the admin "Merge accounts" card says so
      in the same words. Commerce was another agent's territory while this
      slice was built; the other three are equally untouched. Until a
      listener lands, `MergeUserAccounts::handle()` refuses to run at all
      without an explicit `acknowledgedClosedAccountHistoryStaysBehind`
      acknowledgement, and the console makes the operator tick it before
      the submit button unlocks — a correction on the first cut of #81,
      which let an operator close the wrong-direction account with no
      warning at all. The "Merge accounts" card also links to Registration's
      admin search for the address being closed, the one module with a live
      per-email lookup today — Commerce and Admission have none, so that
      check is partial, not a full "what does this account hold" view. A
      real one is `GdprHandlerRegistry::exportFor()` reached honestly,
      through Compliance's own subject-request/export flow once an operator
      workflow exists for it, not a direct read from Identity — see that
      registry's own docblock and `SubjectRequestController`'s: it is
      Compliance's internal orchestration tool for the statutory fan-out,
      gated by three capabilities separate from `ManageUsers`
      (`ViewSubjectRequests`/`HandleSubjectRequests`/`DownloadSubjectExport`,
      the last one because "an operator reading one person's entire record
      across every module is the most privileged read in the application"),
      and every other module only ever writes into it. Identity reading
      from it here would be the first read from outside Compliance and
      would hand a `ManageUsers`-only operator that privileged a read with
      none of that gating or audit trail — wrong boundary to cross for this
      correction.
- [ ] `brand_memberships` and role grants are not moved by a merge either
      (`Actions\MergeUserAccounts`'s docblock has the reasoning) — the
      survivor may not show membership on a brand only the closed account
      visited, and inherits none of its roles. Worth a follow-up once the
      cross-module listeners above exist and de-duplication rules
      (a shared brand on both accounts, `MediaUsage` reassignment) can be
      designed alongside them rather than bolted on later.
- [ ] Self-service account merging (a person proving control of both
      accounts, e.g. an emailed confirmation to the other address) was the
      first design considered for #81 and is still the right shape for a
      person to request on their own — this slice built the operator-console
      version instead (`MergeUserAccounts`'s docblock has the full
      reasoning). Worth reconsidering once the cross-module data move above
      exists, since a self-service merge that cannot honestly say "your
      history moved too" is not much of an improvement on asking support.
- [ ] Importing Steam identifiers from the LanCore migration is blocked on
      #87 and untouched by #81 — a migrated account still gets recognised by
      Steam only after it first signs in that way, same as before.

## Done

- [x] A Steam-only account can set its first password (bugfix round
      2026-09-21, Task 5). `PasswordUpdateData` demanded `current_password`
      unconditionally, which no account with a null hash could satisfy —
      and `LinkedAccounts.vue` sends exactly those accounts to that form.
      `PasswordValidationRules::currentPasswordRulesFor()` answers
      `missing` for an account without a password and the full check for
      everyone else; `SecurityController::edit()` shares `hasPassword`, and
      `Security.vue` hides the current-password field and titles the card
      "Set password". Second half of the same report: Fortify's
      `password.reset`/`password.update` were `guest:web`, so a signed-in
      person opening the mail link landed on the dashboard;
      `throttleUnhookedFortifyRoutes()` now strips that guard (the token
      plus the address is the credential, `NewPasswordController` never
      reads the session). The `verified` gate on `settings/security` stays
      on purpose: an account with a placeholder address sets a real one
      first (#293), and `EmailVerificationBanner` tells it so on every
      page. `tests/Feature/Settings/SecurityTest.php`,
      `tests/Feature/Auth/PasswordResetTest.php`, `tests/SocialSignupTest.php`
      (Steam signup, set password, sign in with e-mail and password).

- [x] A "Sign in with Steam" button on the login page (#81) — Steam sign-in
      used to start only from `LinkedAccounts.vue`, reachable only after
      signing in some other way, which was backwards for a deployment where
      most migrated accounts sign in *by* Steam. The link carries the login
      form's own remember tick through the redirect exactly as this note
      specified.
- [x] An operator-performed account merge (#81, `Actions\MergeUserAccounts`)
      for the duplicate a migrated account gets if it signs in with Steam
      before ever being recognised — a password account and a Steam-only
      account that both belong to the same person. See the Backlog entries
      above for what it deliberately leaves undone.
- [x] Required, informed acknowledgement before a merge can close an account
      (#81 correction): `MergeUserAccounts::handle()` refuses without
      `acknowledgedClosedAccountHistoryStaysBehind`, the console's checkbox
      states plainly what does not move, the submit button stays disabled
      until it is ticked, and the "Other account's email" field links out to
      a check of Registration's admin search for that address. See the
      Backlog entry above for why that check is partial and why this does
      not reach `GdprHandlerRegistry` directly.
- [x] An unconfirmed address is now prompted for everywhere, not only on the
      handful of pages the `verified` route middleware already gates (#81):
      `emailVerified`, a new shared Inertia prop kept off `auth.user` (its
      shape stays fixed at five fields), and `EmailVerificationBanner.vue` in
      `AppTopbarLayout.vue`. Most Steam signups hit this from their very
      first page, since Steam asserts no address for Fortify to have
      verified in the first place.

- [x] ADR-0016 email-enumeration gap closed (GitLab #18) on all three paths
      the issue named: registration (`register.store`), the forgot-password
      request (`password.email`), and the Steam signup completion
      (`auth/{provider}/complete`). None of the three tells a requester an
      address is already in use.
      Registration and Steam signup share the shape: `RegisterData`/
      `CompleteSocialSignupData` validate the address's format only
      (`ProfileValidationRules::emailFormatRules()`, no `Rule::unique`), and
      `Actions\CreateNewUser`/`Actions\CompleteSocialSignup` answer the
      "does this address already exist" question themselves — a real
      account for a new address, `null` for a duplicate, and a notice mail
      (`AuthMailer::sendRegistrationAttemptNotice()`,
      `identity.registration-attempt`) to the *existing* account either
      way, offering the ordinary forgot-password form rather than a token
      this class minted itself. `null` had nowhere to go through Fortify's
      stock `RegisteredUserController` (it unconditionally logs in whatever
      `CreatesNewUsers::create()` returns), so `register.store` now answers
      through this module's own `Http\Controllers\
      AccountRegistrationController` instead —
      `IdentityServiceProvider::throttleUnhookedFortifyRoutes()` swaps the
      route's action onto it post-boot, the same "walk `Route::getRoutes()`
      in a `booted()` callback" trick that method already used for
      middleware, so the route's name, URI and throttling are untouched and
      `Features::registration()` stays enabled (tests gate on it).
      `SocialAuthController::store()` needed no such replacement — it was
      already this module's own controller — just to skip `Auth::login()`
      on `null` and answer with the same redirect either way.
      Password reset needed no Action changes at all: Fortify's own
      `PasswordResetLinkController` already calls a broker whose
      `INVALID_USER` and `RESET_LINK_SENT` differ only in which response
      contract gets built, so `Http\Responses\GenericPasswordResetLinkResponse`
      binds to *both* (`IdentityServiceProvider::configureFortifyResponses()`)
      and answers the same message regardless — no controller or route
      changed.
      Timing: `Illuminate\Auth\Passwords\PasswordBroker::sendResetLink()`
      already wraps its whole body in a fixed-floor `Illuminate\Support\
      Timebox` (its own, pre-existing behaviour — nothing added here), which
      is what password reset relies on. Registration and Steam signup had no
      such floor — a real signup hashes a password (registration) or writes
      a second row and fires two more events (both) that a duplicate never
      pays for — so `CreateNewUser`/`CompleteSocialSignup` each wrap their
      branch decision in the same `Timebox`, 200ms, matching the broker's
      own default rather than inventing a second number.
      `tests/EmailEnumerationTest.php` compares each duplicate branch
      directly against a genuine one made in the same test (status code,
      redirect target, session status message) rather than against a
      hard-coded expectation. `tests/RegistrationTest.php` and
      `tests/SocialSignupTest.php` carry the path-specific shape (no account
      created, existing one untouched beyond the mail).
- [x] Signups can be closed (hotfix 2026-09-13): `EnsureSignupsOpen` on
      Fortify's `register`/`register.store` (wrapped on in
      `throttleUnhookedFortifyRoutes()`, like the limiters) and as the
      `signups-open` alias on the social completion routes; the answer is
      Platform's `OperatorSettings::signupsOpen()` — the operator's switch
      (console, Operator settings, "Let visitors create accounts") under the
      instance cap `SIGNUPS_ENABLED` (config/platform.php). A closed door
      redirects to login with a message rather than 403ing, and the login
      page drops its "Sign up" link (`canRegister`). Not a Fortify feature
      toggle: `Features::registration()` is read at boot and cannot follow a
      row. `SignupGateTest` covers both entrances, the cap and login staying
      open.

- [x] `RecordBrandMembership` resolves `BrandContext` at call time (#142). Under
      Octane the event dispatcher builds listeners from the worker's base
      application, so the constructor-injected context was always empty and no
      membership was ever written on the dev cluster. First contact stays
      registration or login (a session from before the fix needs one fresh
      login per brand): treating every authenticated request as contact would
      make "signed-in non-member" impossible, which the ADR-0052 gate and its
      tests rely on. `tests/BrandMembershipTest.php` reproduces the Octane case
      with a cloned container; rule recorded in `.ai/rules/listeners.md`.
- [x] Registration asks for the username (#143, ADR-0051 §3 amended):
      `RegisterData` and `CompleteSocialSignupData` require it under the shared
      `ProfileValidationRules::usernameRules()`; `UsernameGenerator` stays for
      factories, seeders and the backfill. Username and social-link validation
      messages now say what shape is expected, and the profile form carries a
      hint per platform (#110).
<!-- Move completed items here with the PR that finished them. -->

- [x] ADR-0048 point 5: verification, password reset and the welcome that
      follows the first verification go through Messaging instead of
      Laravel's stock `Illuminate\Auth\Notifications\*` — `Mail\AuthMailer`
      (`sendVerification`/`sendPasswordReset`/`sendWelcome`), `User`
      overrides of `sendEmailVerificationNotification()` /
      `sendPasswordResetNotification()`, `Listeners\SendWelcomeOnVerified`
      on `Illuminate\Auth\Events\Verified`, three templates (`identity.
      verify-email`, `identity.password-reset`, `identity.welcome`) and the
      `identity.welcome` topic. Closes Messaging's own "wire a real caller"
      TODO item — the first non-Registration/Commerce module to call
      `SendMessage`. Along the way, found and fixed a real duplicate send:
      `Registered => SendEmailVerificationNotification` was registered both
      explicitly here and — always, unconditionally, for every Laravel
      11+/12+/13 app built on `Application::configure()` — by the
      framework's own base `EventServiceProvider` (`->withEvents()` is
      chained by default); every registration was quietly sending two
      verification mails. `AuthMailTest.php`, `IdentityServiceProvider`'s
      `registerListeners()` docblock has the detail. The verification URL
      is built exactly like Laravel's own `VerifyEmail::verificationUrl()` —
      absolute against the ambient request, never `BrandUrl`-prefixed, since
      that would sign a relative path and break validation against the
      later absolute request.

- [x] Steam remembers only when asked (WP194 §3.2). Both `Auth::login()`
      calls in `SocialAuthController` (`store()` for a fresh signup,
      `logIn()` for a recognised identity) unconditionally passed
      `remember: true` — every Steam sign-in got a persistent cookie whether
      or not the person asked for one, which a checkbox-free "remember me"
      is not. `redirect()` now stashes the redirect's `remember` query flag
      in the session before handing off to the provider, since nothing else
      survives the round trip to Steam and back; both login calls pull it
      back out (default `false`) and it is gone from the session either way.
      `tests/SocialLoginTest.php`.

- [x] The user list defaults its brand filter from the console selection,
      cleared per list with `brand=all`. Accounts are operator-global
      (ADR-0016), so the filter narrows through `BrandMembership` rather than
      through the account itself.

- [x] Profile replay command (`identity:replay-profiles {--chunk=500}`):
      re-emits `UserProfileUpdated` for every account with the row's current
      values, chunked, idempotent because every consumer upserts. Closes the
      gap Messaging's recipients and Content's authors both had — neither
      saw an account created before its projection existed — and is what
      ADR-0004 promised the read models could always be rebuilt from.

- [x] Auth and settings pages were rendering with **no layout at all**, and
      had been since layout props arrived: `defineOptions({ layout: { ... } })`
      declares props for the layout, not a layout, so `app.ts`'s
      "assign a layout when the page declares none" test never matched them.
      Fixed at the resolver — `createInertiaApp({ layout })` is Inertia's own
      default-layout hook and merges those props into the layout it picks.
      Guarded by `SettingsLayoutTest`, which asserts against rendered HTML
      because the failure is invisible in the Inertia props: right status,
      right component, no shell.

- [x] `/settings` is a page rather than a redirect into the profile form: an
      account hub listing every section with what it covers, reached from the
      topbar's Account menu. The section list moved to
      `resources/js/lib/settingsSections.ts`, so the hub and the sub-navigation
      beside every settings page read one list instead of two copies.

- [x] Account scope settled — no new ADR needed: ADR-0016 already decided one
      account across the operator's brands. SPEC.md updated to say so.
- [x] `brand_memberships` (ADR-0016): model, migration, factory, and the
      `RecordBrandMembership` listener on `Login`/`Registered` — first contact
      with a brand writes the row, later contacts never touch it. Brand-scoped
      via `BelongsToBrand`.
- [x] Authentication flows run against the resolved brand context with
      brand-scoped session cookies (Platform's ResolveBrand supplies both);
      cross-module import boundaries are enforced by the root architecture
      test for every module including Identity.
- [x] Registration, email verification, and 2FA challenge login
      (`Features::registration()`, `Features::emailVerification()`,
      `Features::twoFactorAuthentication()`), `CreateNewUser`
      (`Fortify::createUsersUsing`), the Fortify view callbacks
      (register/verify-email/two-factor-challenge/confirm-password), and the
      `Register.vue`/`VerifyEmail.vue`/`TwoFactorChallenge.vue`/
      `ConfirmPassword.vue` pages.
- [x] Account state (`AccountState::Active`/`Locked`) enforced at login via
      `Fortify::authenticateUsing` (`Actions\AuthenticateUser`) — a locked
      account fails authentication even with the correct password, on both
      the plain and two-factor-redirect login paths.
- [x] Session list + revocation on the Security settings page:
      `Models\Session` (a model over Laravel's own `sessions` table, not a
      module-owned table — see its docblock for why it is exempt from
      ADR-0014's ULID-key rule), `Queries\ActiveSessionsQuery`,
      `SessionController` (`sessions.destroy` behind `password.confirm`,
      `sessions.destroy-others` a direct delete), `SessionList.vue`.
