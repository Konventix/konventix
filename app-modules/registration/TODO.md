# Registration — TODO

> Living document. Update it in the same PR that changes the code: check off
> what you completed, add follow-ups you discovered. Do not generate status
> dashboards, percentages, or summary tables here.

## Next

- [ ] Register policies for viewing, approving, and managing types and quotas
      (the `Permission` catalog exists — `src/Enums/Permission.php` — but is
      not wired to `permissions:sync` or any Policy class yet).
- [ ] A frozen type cannot be superseded from the console: an organizer whose
      terms have changed must build the replacement by hand. A "duplicate this
      type" action would make the intended path (new type, old one hidden by
      visibility) as easy as the refused one.
- [ ] Grants take a free-text `role`. Admission decides what a role means, so
      a typo becomes a badge nobody can check in — the roles an event knows
      belong in a picker, which needs a contract with Admission.
- [ ] The type list shows no sales figures. `RegistrationStatsController`
      already computes per-type counts for the event dashboard; the same
      numbers belong on the type row.

## Backlog (Phase 2 — release scope)

- [ ] Waitlist prerequisite follow-up from the settlement build: decide how
      a waitlisted-then-promoted registration interacts with the
      gate-dependent settlement emission (promotion happens long after
      submission — an `on_payment` type's settlement request emitted at
      original submission may price differently by then).
- [ ] `RegistrationStatsController`'s per-type "sold" figure sums capacity
      across every quota a type references and treats Submitted/Approved/
      Confirmed as equally "sold" — fine for the dashboard skeleton, but the
      real quota-composition/waiting-list question below should settle
      whether a type with multiple quotas needs a per-quota breakdown
      instead of one summed number.
- [ ] Amendment admin UI: `AmendRegistration` is domain-only — adding grants
      currently means tinker. Needs a screen on the registration detail page
      (add grants, declare the delta lines when it is priced) behind a new
      `registration.amend-registrations` capability. Same shape as the token
      management gap below; fold both into the same admin pass.
- [ ] Amendment pricing source (ADR-0036 follow-up): `AmendRegistration` takes
      explicit delta lines because `GrantData` carries no price and a type's
      `price_declaration` prices the *type*. If per-grant pricing ever lands
      on `RegistrationType`, the priced path should derive lines instead of
      trusting the caller.
- [ ] Token management UI: `InvitationToken` has model/guards/burn logic but
      no operator screen yet — issue/revoke currently means tinker. The type
      editor now exists to hang it on: an `invite_only` or `link` type's own
      screen is where its tokens belong.

## Done

- [x] The registration list defaults both its brand and its event filter from
      the console selection, each cleared per list with `brand=all` /
      `event=all`; the event names come from this module's own `EventSummary`
      projection. The CSV export link now carries the filters the list is
      showing, so it exports what the operator is looking at.

- [x] Participant "my registrations": a list and a detail page at
      `/my/registrations`, reached from the account menu. Authorization is
      ownership rather than a capability — there is no role that grants
      "see your own things" — and the brand scope holds on top of it, so an
      account that is a member of two brands sees each brand's registrations
      only on that brand's host (ADR-0016). The event is named from this
      module's own `EventSummary` projection, never Event's tables
      (ADR-0004). Deliberately offers no actions: amendment, transfer and
      cancellation (ADR-0036) are organizer-side Actions with no
      self-service path yet, and a button that filed a support request in
      disguise would be worse than none.

- [x] The public form is reachable from the site. `/events/{event}/register/{type}`
      had no inbound link anywhere in the UI — it worked, and nobody could
      find it. `PublicOfferData` + a contribution to Event's `event.landing`
      slot puts the brand's public types on the landing page with their
      declared price and whether a reviewer stands in the way. Only `Public`
      types are listed: advertising a `Link` or `InviteOnly` type to someone
      holding no token would defeat the point of the axis (D-COM-02).

- [x] Registration-type and quota console (ADR-0029) — the offer had no screen
      at all: types were created by seeder or tinker, which made the central
      object of the product the one thing an operator could not touch. The
      editor carries the four axes (ADR-0005), the grants, the price
      declaration (ADR-0017) and the quota links; quotas get their own screen
      because they are a standalone entity shared by several types.
      Two configuration mistakes are now refused at the source rather than at
      submission time in front of a registrant: a priced settlement with no
      price declaration (which `RequestSettlement` throws on), and an
      `on_payment` gate on a settlement nobody pays (nothing would ever
      confirm such a registration). Both guards live in the Actions, so
      tinker is refused on the same grounds as the form.
      Terms freeze once registrations exist — event, approval, settlement,
      gate, payment terms, grants and price stop being editable, while name,
      visibility and quota links stay. The Actions read a type's *current*
      axes when a payment lands or an approval is given, so an edit under live
      registrations reinterprets them rather than migrating them.
      Quota capacity may not drop below what is already consumed.

- [x] Event read model (`Models\EventSummary`, `Listeners\ProjectEventSummary`)
      projected from `EventCreated`/`EventDetailsUpdated`/`EventArchived`
      (ADR-0004), so the type editor offers event names instead of asking for
      a pasted ULID. Archived events drop out of the picker but keep their row,
      or existing types would lose their label.

- [x] Amendment as delta orders (ADR-0036 — completes Slice 4).
      `RegistrationAmendment` records the delta; `AmendRegistration` guards
      that the registration is Confirmed and the delta non-empty, consumes one
      quota unit per added admission all-or-nothing (rejecting rather than
      overbooking), and always emits `RegistrationAmended`. Money is
      conditional: only a delta carrying explicit lines requests settlement,
      keyed on the amendment's own ULID, so Commerce writes a *second* order
      and invoice while the original stays untouched (ADR-0031). Commerce's
      idempotency moved from `settles_id` to `request_key` (unique on
      `settles_type, settles_id, request_key`, backfilled to `settles_id`);
      Admission's moved from "any admissions?" to per-`amendment_id`, which
      is what stopped deltas being silently swallowed. `ReleasesQuotas` now
      gives back the amended units too — releasing only the submission's one
      unit leaked capacity permanently on every amended registration.
      Post-invoice *transfer* (credit note + re-issue) still lands with
      Slice 7's credit notes.
- [x] Slice 4 — Registration completion (minus amendment, see Backlog).
      Waitlist (D-REG-12): `WaitlistEntry` with billing snapshot, states
      queued → offered → converted/expired/withdrawn; `JoinWaitlist` from
      the public form on quota exhaustion (all-or-nothing, one live
      position per registrant+type); FIFO `PromoteFromWaitlist` on
      `QuotaReleased` with a quota HOLD (consumed at offer, released at
      expiry — un-snipeable), 48h offers, signed claim links mailed via
      Messaging (`registration.waitlist-offer`, de/en); conversion through
      `SubmitRegistration(fromWaitlist:)` without double-consuming;
      `registration:expire-waitlist-offers` every 15 min — expiry releases
      units, which re-promotes, so the queue drains itself.
      Invitation/link tokens (D-COM-02): `InvitationToken` (max_uses null =
      shareable link, 1 = personal invite; expiry, revocation), reachability
      guard in the controller + use burned under lock in the Action.
      Organizer list: `admin/registrations` (data-table, status filter,
      read-model search), bulk approval through `ApproveRegistration` per
      row (with the BrandScope-hardening relation pre-load), streaming CSV
      export, nav entry. Registrant read model (`registration_registrants`)
      from Identity's events; `RegistrationGdprHandler` (export + anonymize
      with tombstones) registered via `registerGdprExporters` — the root
      architecture test now sanctions Compliance's `Gdpr`/`Enums` contract
      surface. Transfer, pre-invoice half (ADR-0036): `invoiced_at` stamped
      by Commerce's `InvoiceIssued` (payload gained settles_type/id),
      `UpdateBillingParty` edits freely before and refuses after.
      `tests/{WaitlistTest,InvitationTokenTest,RegistrationAdminTest}.php`.

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
