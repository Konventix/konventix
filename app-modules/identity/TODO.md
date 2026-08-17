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

- [ ] Profile replay command (`identity:replay-profiles`): re-emit
      `UserProfileUpdated` for every account so downstream read models can be
      rebuilt from the event contract, which is what ADR-0004 promises they
      can be. Two modules need it and neither may read this module's tables —
      Messaging's recipients and Content's authors both only see accounts
      created after their projection existed. One replay closes both, and
      every future read model inherits the fix.
- [ ] `SocialProvider` driver contract plus a `social_identities` table, with
      Steam as the first driver and at least one non-gaming driver to prove the
      abstraction is not Steam-shaped.
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
- [ ] `src/Gdpr/` handler: export the account, social identities and session
      history on `DataExportRequested`; anonymize on `UserDeletionRequested` and
      emit `UserAnonymized`.
- [ ] Tombstone row on anonymization (decision log Round 6, D-BATCH): the
      anonymize path must leave a row behind (scrubbed name/email/etc., ULID
      unchanged) rather than deleting `users`, so every other module's
      cross-module ULID reference to this user stays resolvable.
- [ ] `AccountState` only has `Active`/`Locked`. `pending` is effectively
      covered by `email_verified_at` (Fortify's `emailVerification` feature)
      and doesn't need its own state; `anonymized` belongs with the `Gdpr/`
      handler above rather than being added early and left unused.
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

## Done

<!-- Move completed items here with the PR that finished them. -->

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
