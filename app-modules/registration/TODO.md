# Registration — TODO

> Living document. Update it in the same PR that changes the code: check off
> what you completed, add follow-ups you discovered. Do not generate status
> dashboards, percentages, or summary tables here.

## Next (Phase 1 PoC — one registration flow, multiple types, free settlement)

- [ ] Register policies for viewing, approving, and managing types and quotas
      (the `Permission` catalog exists — `src/Enums/Permission.php` — but is
      not wired to `permissions:sync` or any Policy class yet).

## Backlog (Phase 2 — release scope)

- [ ] Waitlist prerequisite follow-up from the settlement build: decide how
      a waitlisted-then-promoted registration interacts with the
      gate-dependent settlement emission (promotion happens long after
      submission — an `on_payment` type's settlement request emitted at
      original submission may price differently by then).
- [ ] Invitation and link tokens for `invite_only` and `link` visibility. Until
      these exist, the public routes and `SubmitRegistration` both reject any
      non-`public` type with a 403 — see `RegistrationController::guardReachable()`
      and `SubmitRegistration::guardVisibility()`.
- [ ] Registrant read model fed by Identity events; the GDPR exporter in
      `src/Gdpr/`.
- [ ] Organizer-side registration list with filters, bulk approval, and export.
- [ ] Register navigation entries with Platform (brand-area entries — the
      operator-console `event.admin.dashboard` slot contribution below is a
      different registration, not a substitute).
- [ ] `RegistrationStatsController`'s per-type "sold" figure sums capacity
      across every quota a type references and treats Submitted/Approved/
      Confirmed as equally "sold" — fine for the dashboard skeleton, but the
      real quota-composition/waiting-list question below should settle
      whether a type with multiple quotas needs a per-quota breakdown
      instead of one summed number.
- [ ] Resolve the open questions in `SPEC.md` that block Phase 2 — where the
      price lives, quota composition, and deferred attendee data — and promote
      the answers to ADRs.
- [ ] Quota composition/waiting lists (`SPEC.md` open questions 3–4):
      `SubmitRegistration::consumeQuotas()` currently treats every quota a
      type references as required-and-atomic (all-or-nothing check before any
      consumption) with no waiting-list fallback.
- [ ] Registration-owned waitlist (decision log Round 6, D-REG-04, user
      override — new release scope): when `consumeQuotas()` finds a quota
      exhausted, create a waitlist entry instead of failing the submission
      outright; model the entry's own state (queued, offered, expired,
      converted) and the promotion path when a unit frees up via
      `QuotaReleased`. Resources has no queue of its own — this is the only
      waitlist in the system.

## Done

- [x] ADR-0017 price declaration: `PriceDeclarationData` value object
      (`unit_amount_net` numeric-string, `TaxClass` standard/reduced,
      `PriceDisplay` net/gross) as a nullable JSON cast on
      `RegistrationType.price_declaration` — null means "no price", never a
      zero price — plus `terms_days` (default 14; the exhibitor factory
      profile carries 30). Billing-party columns on `registrations`
      (address line, postal code, city, ISO country, VAT ID);
      `SubmitRegistration::guardBillingParty()` requires the full address
      for `settlement: invoice` types only (a legally valid German invoice
      needs it), enforced per type in the Action because static `rules()`
      cannot see which type the URL addressed. Public form
      (`Register.vue`/`RegistrationFormData`) shows the address block and
      declared price for invoice types.
- [x] `RegistrationSettlementRequested` emitted gate-dependently (Phase 2
      iteration interview): `on_payment` types emit at submission (the
      registrant must be able to pay before anything confirms), every other
      gate emits at confirmation — the two points partition on the gate, so
      no registration emits twice. `Actions\RequestSettlement` builds the
      scalar payload (billing party, lines with tax *class* not rate,
      price display, terms). `settlement: external` deliberately does NOT
      emit — money moves off-platform; door payment is a Commerce POS
      concern (ADR-0018), not this axis. `SubmitRegistration` now confirms
      `on_submit` types regardless of settlement (previously free-only).
- [x] Consume Commerce's `OrderPaid` (`Listeners\RecordPaymentOnOrderPaid` →
      `Actions\RecordOrderPaidForRegistration`, scalar-only, idempotent,
      `withoutBrandScope()` scoped to the payload's brandId): payment status
      → `Paid` + `RegistrationPaymentStatusChanged`, and `on_payment` gates
      confirm — with approval as a precondition, not a gate: payment before
      review records but does not confirm; `ApproveRegistration` completes
      the confirmation symmetrically when payment already landed.
      `tests/OrderPaidConsumptionTest.php`, `tests/SettlementRequestTest.php`.
- [x] Invoice-settled exhibitor registrations end to end (Phase 2 definition
      of done): submit → approve → confirm → `RegistrationSettlementRequested`
      → Commerce order + gapless invoice + archived ZUGFeRD PDF → **invoice
      mail to the billing party with the sealed document attached (Slice 2,
      Commerce's `SendInvoiceMail` through Messaging)** → manual payment
      booking in the operator console → `OrderPaid` → payment status paid.
      The flow is complete.

- [x] Write `SPEC.md` — purpose, ownership boundary, event contract.
- [x] Model `RegistrationType` with the five axes from ADR-0005 as enum-backed
      columns (`visibility`, `approval`, `settlement`, `confirmation_gate`),
      plus `grants` as a JSON column cast to a `Spatie\LaravelData\DataCollection`
      of `Konventix\Registration\Data\GrantData` (ADR-0009). ULID primary
      keys, `BelongsToBrand`.
- [x] Model `Registration` with two independent status fields
      (`RegistrationStatus`, `PaymentStatus`) and a nullable organization/
      billing-recipient invoice recipient. `registrant_user_id` is required,
      never nullable (D-REG-08).
- [x] Model `Quota` as a standalone entity, referenced by `RegistrationType`
      through the `registration_type_quota` pivot; `consume()`/`release()`
      run inside `SubmitRegistration`/`RejectRegistration`/`CancelRegistration`'s
      transactions with `lockForUpdate()`.
- [x] Submit → (approve) → confirm → reject/cancel flow as Actions
      (`SubmitRegistration`, `ApproveRegistration`, `RejectRegistration`,
      `ConfirmRegistration`, `CancelRegistration`), one per transition,
      `DB::transaction` inside each. Emits `RegistrationSubmitted`,
      `RegistrationApproved`, `RegistrationRejected`, `RegistrationConfirmed`,
      `RegistrationCancelled`, `QuotaExhausted`, `QuotaReleased`.
- [x] Feature tests for `on_submit`/`on_approval` with `settlement: free`
      end to end, plus fixtures for all four ADR-0005 example type profiles
      (`tests/RegistrationTypeTest.php`, `tests/SubmitRegistrationTest.php`,
      `tests/ApproveRegistrationTest.php`, `tests/RejectRegistrationTest.php`,
      `tests/CancelRegistrationTest.php`, `tests/PublicRegistrationFlowTest.php`).
      `on_payment` is proven inert (a submission never auto-confirms on that
      gate) rather than exercised end to end — Commerce's `OrderPaid` is not
      consumed yet.
- [x] Public registration form: `GET`/`POST /events/{event_ulid}/register/{type}`
      behind `auth` middleware (D-REG-08), `RegistrationFormData` (Inertia
      props) and `SubmitRegistrationData` (validation, both `#[TypeScript]`)
      as the single source per ADR-0009. Vue page at
      `resources/js/pages/registration/Register.vue` (host-level — module-local
      `resources/js/pages` is not wired into Inertia's page resolution yet,
      see `.ai/rules/resources.md` vs. `Konventix\Event\Http\Controllers\EventLandingController`'s
      docblock for the same caveat).
- [x] Capability catalog in `src/Enums/Permission.php` (not wired to
      `permissions:sync` or Policies yet).
- [x] `registration/EventStatsCard` slot contribution to Event's operator
      console dashboard (ADR-0029, ADR-0003 "slots are an activation gate
      point"): `RegistrationServiceProvider::registerSlots()` (the new
      `ModuleServiceProvider::registerSlots()` hook, mirroring
      `registerBlockTypes()`) registers the card on the
      `event.admin.dashboard` slot; `RegistrationStatsController` (`GET
      /admin/events/{event}/registration-stats`, behind
      `registration.view-registrations`) is the card's own data source —
      counts by `RegistrationStatus` plus per-type sold/quota, all through
      the explicit `withoutBrandScope()` escape hatch since the admin host
      resolves no brand (ADR-0010, ADR-0029; see the controller's docblock
      for why quotas are read via `DB::table('registration_type_quota')`
      rather than an eager-loaded relation). Vue component at
      `resources/js/components/registration/EventStatsCard.vue`.
      `tests/RegistrationStatsTest.php` covers capability + operator-host
      enforcement and the counts/per-type numbers for a seeded event.
