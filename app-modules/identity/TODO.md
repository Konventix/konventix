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
- [ ] Minimal shared Inertia props: ULID, display name, avatar URL, locale.
      Nothing computed, nothing eager-loaded, no permission map
      (lessons-from-lancore #5).
      Still open — `auth.user` in `App\Http\Middleware\HandleInertiaRequests`
      (host-level, out of this module's file scope) shares the full user
      model today rather than the four-field shape SPEC.md describes.

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
- [ ] ADR-0016 gap, flagged rather than worked around (`RegisterData`'s
      docblock): a duplicate email on the registration form surfaces as an
      ordinary "already taken" validation error, not the "indistinguishable
      from success" response the ADR requires. Closing this means replacing
      Fortify's stock `RegisteredUserController`/`RegisterResponse` — it
      cannot auto-login on a duplicate — which is a bigger change than this
      PR's scope. The same gap already existed for password reset before this
      PR and is unchanged here.
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

- [ ] No "Sign in with Steam" button exists on the login page yet — Steam
      sign-in today only starts from `LinkedAccounts.vue`, which links an
      account and does not authenticate. When one lands, it must pass the
      login form's own remember checkbox through the redirect:
      `{ query: { remember: form.remember ? 1 : 0 } }`, matching what
      `SocialAuthController::redirect()` now reads.

## Done

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
