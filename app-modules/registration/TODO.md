# Registration — TODO

> Living document. Update it in the same PR that changes the code: check off
> what you completed, add follow-ups you discovered. Do not generate status
> dashboards, percentages, or summary tables here.

## Next

- [x] Entry conditions, end to end (round 4, Task 31, ADR-0066 slice B1;
      D-REG-19/20/21/22/23/24). The console (`/admin/entry-conditions`:
      index, editor with a character budget and a live preview of the
      registrant's own checkbox, archive instead of delete); the type
      editor's *Entry conditions* card with the alternative-type pointer and
      the warning a live type owes before saving; one required checkbox per
      condition on the public form, with the assertion text verbatim in a
      real `<label>`; `SubmitRegistration::guardEntryConditions()` refusing
      on `entryConditions.{id}` beside the "buy the regular ticket instead"
      link, then writing the snapshotted assertions,
      `RegistrationEntryConditionAsserted` per condition and one
      `RegistrationConsentGiven` with the binding context;
      `DecideEntryConditionAssertion` (verify/refuse/waive) behind
      `registration.verify-entry-conditions` on a new registration detail
      that shows *not asserted* and *no longer required* rows, plus the
      entry-condition state filter that is the `operator_checked` worklist;
      the GDPR export and anonymization of assertions, and
      `PruneEntryConditionAssertions` on the registration's own
      `EventConcluded` clock. **Deliberate gaps, all of them decided:**
      `acceptedLegalTexts` is carried but no control renders it yet — the
      `registration.consent` slot and Compliance's `RecordConsentDeclaration`
      are Task 32; the door (`entry_condition_keys` on
      `RegistrationConfirmed`, `RecordDoorVerification`) is Task 33.
- [ ] A waitlist conversion declares nothing (spec §15, noticed in Task 31):
      `WaitlistEntry` holds a billing snapshot, not a tick, so converting an
      offer for a type with entry conditions writes no assertion and the
      registration shows *not asserted* until an operator waives it.
      Collecting the declaration on the offer link belongs with the
      per-holder declarations a claim link would collect (D-REG-20's
      follow-up) — one design, not two.
- [x] Entry conditions and policy bindings — the behaviour-neutral
      foundation (round 4, Task 30, ADR-0066; D-REG-17/18/24, and the
      Compliance half under D-CPL-02/03/04/05/08/09/16). Tables
      `registration_entry_conditions`, `registration_type_entry_condition`
      and `registration_entry_condition_assertions`, plus
      `registration_types.alternative_registration_type_id`; models
      `EntryCondition` (brand-wide, optional `event_id`, `active`/`archived`
      scopes) and `EntryConditionAssertion` (unique per
      `(registration, condition)`, `restrict` on the condition, cascade with
      the registration); enums `EntryConditionVerification`,
      `EntryConditionAssertionState`, `EntryConditionDecisionSource`,
      `EntryConditionReasonCode`; events `EntryConditionCreated`/`Updated`/
      `Archived` (all `AuditableEvent`),
      `RegistrationTypeEntryConditionsChanged`,
      `RegistrationEntryConditionAsserted`,
      `RegistrationEntryConditionDecided`, `RegistrationConsentGiven`; Data
      `EntryConditionData` and `PublicEntryConditionData`;
      `Permission::ManageEntryConditions` and
      `Permission::VerifyEntryConditions`, the second one in
      `registration.desk` and the first deliberately not (D-REG-24); and the
      module's first metrics catalogue
      (`entry_condition_assertions_total{brand, verification}`,
      `entry_condition_decisions_total{brand, state, source}` — never the
      condition's key, T-11). **Nothing is wired into a form, an action or a
      page**: no existing assertion changed, and an unbound policy resolves
      exactly as it did. The console, the public checkboxes,
      `SubmitRegistration::guardEntryConditions()`, the GDPR export and the
      retention rule are Task 31; the door is Task 33.
- [x] Partial cancellation with a per-unit refund (round 4, Task 23,
      D-REG-15): `registration_quantity_reductions` (ULID = the idempotency
      key every other module keys on), `Actions\ReduceRegistrationQuantity`
      (confirmed only, `1 <= N < quantity`, releases N units one at a time so
      the waitlist makes N one-unit offers, leaves `amendedUnits()` alone),
      `Events\RegistrationQuantityReduced`,
      `Permission::ReduceRegistrationQuantity` (**not** in
      `registration.desk`), `POST /admin/registrations/{registration}/reduce-quantity`
      with a dialog on the list row, audit `registration.quantity-reduced`,
      and the sixth lifecycle mail `registration.quantity-reduced`. The
      ordering that keeps a used ticket's money from going back is carried by
      listener semantics alone — Admission's listener is synchronous and
      throws, Commerce's is queued `afterCommit` — and is documented in the
      Action's docblock. Not built: buyer self-service (a later item), and
      *raising* a quantity, which is still an amendment's job.
- [x] Quantity on the registration form (bugfix round 2026-09-21, Task 19):
      `registrations.quantity` (default 1) and
      `registration_types.max_quantity_per_registration` (nullable, null
      means one). `RegistrationFormData.maxQuantity` is
      `min(type cap, remaining quota)` and `Register.vue` shows a number
      input only above one; `SubmitRegistration` guards the cap, consumes
      `quantity` units all-or-nothing and refuses "Only N left." as a
      `quantity` form error, while nothing left still queues on the
      waitlist at one unit; `RequestSettlement` sends one line at
      `quantity` × the per-unit net; `ConfirmRegistration` multiplies every
      grant once in the `RegistrationConfirmed` payload, so Admission,
      Resources and Community changed nothing; `ReleasesQuotas` gives back
      `quantity + amended units`. Not built: changing the quantity of an
      existing registration (an amendment adds grants, and a *smaller*
      quantity has no path at all — it is a cancellation today), and the
      waitlist still queues and promotes one unit per entry however many
      the person asked for.
- [x] Every event a registrant sees links to its page (bugfix round
      2026-09-21, Task 17c): `MyRegistrationData` and `PublicOfferData`
      carry `eventSlug` from this module's own `EventSummary` (null until
      the summary has arrived, and on the landing page, which is that page
      already); `my/Index.vue`, `my/Show.vue` and `ShopOffers.vue` link the
      name beside — never around — the row's own link. `EventOffers.vue`
      stays unlinked on purpose: it renders on the event's landing page and
      inside the `tickets-for-event` block, which names its event itself.
- [x] #234 / D-COM-20 — one end price on every public surface (round 4,
      Task 21): `PublicOfferData` and `RegistrationFormData` carry
      `endPrice` (`Platform\Data\EndPriceData`), computed by
      `PublicOffers::endPricesFor()` through Platform's
      `Pricing\EndPriceCalculator` — one resolution per list, at call time
      (`app()->bound()`), never injected into the slot closures the
      `SlotRegistry` singleton outlives the request with. The landing slot,
      the `tickets-for-event` block, the `shop.index` slot and the
      registration form all render it through one `OfferPrice.vue`
      (`displayUnitAmount()` + `taxNote()`), falling back to the declared
      net amount with "excl. VAT" where nothing could be computed. ADR-0006
      is unchanged: a `net` type still shows its labelled net amount.
- [x] Category-quota ceiling, the consumer half (design §12b, #282):
      `quotas.resource_type_id` (nullable ULID, no FK) links a quota to a
      Resources category, set from the quota form's category select
      (`registration.quota-form.categories`, filled by Resources — this
      module still never queries `resource_types`, ADR-0004).
      `Listeners\AdjustQuotaOnPlaceSupplyChanged` consumes/releases the
      linked quota's unit on Resources' `PlaceSupplyChanged`, reusing
      `ConsumesQuotas`/`ReleasesQuotas`' own per-quota lock-and-emit rather
      than new arithmetic, brand from the payload, idempotent via
      `quota_supply_change_receipts` keyed on the event's `changeId`. Not
      built, on the operator's own call: automatic capacity sync with the
      plan's place count, a queue inside Resources, and the unit mismatch
      where a seating grant grants more than one place per quota unit.
- [ ] `registration-types/Edit.vue` is still around 490 lines after the
      grants card moved out (S4, seating): the offer card and the price
      card are the next two extractions before the page is under the
      300-line guideline.
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

- [ ] `registration:expire-waitlist-offers` should get the same
      `->withoutOverlapping()` Task 11 added to Commerce's
      `commerce:expire-carts` (Task 9 review minor): a rush that makes one
      sweep run long enough to still be going at the next fifteen-minute
      tick must not stack a second run on top of it.
- [ ] Follow-ups from the filter-contract review (2026-08-25), none blocking:
      `export()`'s audit context now records resolved filter values and that
      path is untested; the registrations list clears `filters.event` to
      `undefined` on a brand change while the def's `clearValue` is `'all'` —
      two spellings of "no event"; `registration-types/Index.vue` still uses
      native `<select>`s where the console lists moved to shadcn Select.

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
- [x] Sales window per registration type: columns, admin form, landing-page
      filter, refusal at submission and at the cart line (#218).

## Done

- [x] Attendee list on the event page (bugfix round 2026-09-21, Task 15,
      ADR-0064): `Attendees\PublicAttendees` (confirmed, one per person,
      named only when `Registrant.listed` and a known `username`, ordered
      by username) feeds the `event.landing` card
      `registration/EventAttendees` (order 15; count, a dozen names, a link
      on) and `GET /events/{slug}/attendees` (`AttendeesController`,
      `pages/event/Attendees.vue`, paginated; 404 for a hidden event or a
      switched-off list before any login redirect, sign-in only for a
      `members` list). `Registrant` gained `username`/`listed` from
      `UserProfileUpdated`, `EventSummary` gained
      `attendee_list_visibility` from `EventAttendeeListPolicyChanged`
      (backfilled once from `events` by the migration) plus
      `scopeVisibleOnLanding()`. `AttendeeData` is username and an optional
      literal `/u/{username}` — the slot and page tests pin that no display
      name, e-mail, organization or user ULID is in either payload. Run
      `identity:replay-profiles` after deploy so existing registrants get
      their handles; until then they are counted, not named.
- [x] `tickets-for-event` static-page block (bugfix round 2026-09-21,
      Task 7): `Blocks\TicketsForEventBlock` puts one event's public offers
      on any composed page, registered through `registerBlockTypes()` like
      Event's `event-hero`. The offers query moved into
      `Offers\PublicOffers`, shared with the `event.landing` slot, and the
      shop's listing guard into `EventSummary::scopeListedPublicly()`,
      shared with the block — so neither pair can drift. The block answers
      `found: false` for an unknown, cross-brand, draft, unlisted or
      archived event. `EventOffers.vue` grew a `heading` prop and a
      `useId()` heading id so the block can reuse it.
- [x] A successful submit leaves the registration form (bugfix round
      2026-09-21, Task 4): `RegistrationController::store()` used to answer
      every non-cart success with `registration.show`, which re-rendered the
      empty form under a toast — and since `commerce:shop` defaults to off
      per brand, an `instant`/`on_payment` type on sxlan.dev never showed
      the payer their pay page. Now: the cart branch stays; an `instant`
      settlement whose request has gone out (`settlementRequested()`, the
      per-gate rule formerly `settlementReachedTheCart()`) redirects to
      Commerce's `commerce.pay.show` for the registration when the shop is
      off (`Route::has()`, no import — its controller hands off to the cart
      or to `registration.mine.show` itself when no direct order exists);
      everything else (free, invoice, external, approval-gated) lands on
      `registration.mine.show`. A waitlisted submission goes to the event
      landing page via the `EventSummary` slug (`event.show`), or to
      `registration.mine.index` when nothing is projected — `/my/registrations`
      lists `Registration` rows only, so a `WaitlistEntry` is invisible there.
      Follow-up: neither surface shows the queue *position*; a "waitlisted"
      row on `/my/registrations` (read from `waitlist_entries`, with the
      position and the offer state) would give the waitlist its own page.

- [x] Type-editor slot for seating (seating design §7.1/§9.3, #S4, #275): the
      grants card is its own component,
      `resources/js/components/RegistrationTypeGrantsCard.vue`, and a
      `seating` row renders whatever the
      `registration.type-editor.seating-fields` slot contributes — resolved
      by `RegistrationTypeAdminController@edit` with the type's brand set in
      `BrandContext`, because `SlotRegistry` gates a gateable module on that
      brand's activation and the admin host resolves none. This module still
      reads no `resource_types` row itself (ADR-0004); Resources' own
      contribution is the only source of category names, tokens, symbols
      and — per the operator's 2026-09-10 decision that a category's ceiling
      is a hand-typed Registration quota capacity (#282) — each category's
      bookable place count, shown beside its checkbox next to the quota
      cards' consumed/capacity figures on the same screen.
- [x] Payload projection for seating (seating design §7.1, #S3):
      `GrantData::toEventPayload()` is the one place that turns a grant into
      the scalar shape `RegistrationConfirmed` and `RegistrationAmended`
      carry — `resource_type_ids` and `seating_mode` ride alongside the
      existing `resource_type_id`, null on every kind but `seating`. Both
      events also gain `registrantUserId`, read straight off
      `Registration::$registrant_user_id` (required, never nullable):
      personal data reaching Resources for the first time, there only so the
      seating module can authorise the person picking their own seat and
      answer a data-subject request later — nothing else rides on it. Purely
      additive: every existing consumer (Admission, Community, Competition,
      Resources' own `resource_claim` fulfilment) still reads the same keys
      it always did. SPEC.md's contract table updated in the same commit.

- [x] Seating grant (seating design §7.1, #S3): `GrantKind::Seating`,
      `Enums\SeatingMode`, `GrantData::resourceTypeIds` / `seatingMode`,
      `Actions\Concerns\GuardsSeatingGrants` on type create/update and on
      amendments — one seating grant per list, categories and mode required,
      no category both first-fit-claimed and offered for picking. `grants` is
      one of the axes `GuardsTypeConfiguration::frozenAxes()` locks once a
      type has registrations, so this is not just a validation rule: a
      seating grant has to be configured on a type before it sells anything,
      an operational ordering constraint the operator has to know about
      (ADR-0060) — there is no "add seating to a live type" path.

- [x] ADR-0048 point 3 (Mail Task 5): a registration tells its holder every
      time its state changes. Five new listeners
      (`Listeners\MailOnRegistration{Submitted,Approved,Rejected,Confirmed,Cancelled}`)
      on Registration's own events, each re-reading `Registration` with its
      `registrationType` and calling the new `Mail\RegistrationMailer::send()`
      (the `PromoteFromWaitlist::notify()` try/`Log::warning` shape, so a
      mail that cannot be delivered never rolls back or crashes the state
      transition). One shared `Data\RegistrationMailData` payload: no field
      is a precomputed sentence — `nextStep` (`approval`/`pay`/`invoice`/
      `confirmed`, computed off the type's own axes by
      `Listeners\Concerns\ComputesNextStepCode`), the raw rejection
      `reason` code, and `cancelReason`
      (`cart_expired`/`registrant`/`operator`, derived from
      `RegistrationCancelled`'s `reasonCode`/`cancelledByUserId` against the
      registrant) all ride as codes the five bodies branch on with
      whitespace-safe `@if`/`@elseif`, since `SendMessage` only resolves the
      recipient's locale at send time and a PHP-computed sentence could not
      know it yet. Fixed along the way: eager-loading `registrationType`
      with a bare `with('registrationType')` returned null on the admin
      host (`RegistrationAdminController::bulkApprove()` — BrandScope
      hardens to 1=0 there, `.ai/rules/app-modules-src.md`'s relation trap),
      which surfaced as a silently rolled-back `ApproveRegistration`
      transaction (its own blanket `catch (\Throwable)` swallowed the
      error) — every listener now escapes the relation explicitly.
      `tests/RegistrationMailsTest.php`.

- [x] ADR-0047 point 6 (Task 14), the waitlist/`on_payment` pricing
      backlog note above re-checked against a real rush rather than
      theory: `konventix:seed --rush`'s "Rush ticket" type is exactly
      `instant`/`on_payment`/capacity 300, and the k6 rush
      (`tests/Load/ticket-rush.js`) drove 1,000 arrivals through it —
      300 quota-consuming registrations, the rest onto the waitlist
      (`JoinWaitlist`, `QuotaExhaustedException`) via the same
      `RegistrationController::store()` path a real sold-out submission
      takes. The concern the note names — a waitlisted-then-promoted
      registration's `on_payment` settlement pricing itself against a
      submission-time snapshot that may be stale by promotion — was not
      exercised (`PromoteFromWaitlist` never ran during the rush) and
      stays exactly the open follow-up the backlog item already
      describes; nothing here settles it.

- [x] ADR-0047 point 5 (Task 12), the public registration form stands
      behind the waiting room too: `ensure-waiting-room` added beside
      `require-consent` on `registration.show`/`registration.store`
      (`routes/web/registration.php`) — this module names only Platform's
      alias string, importing neither Commerce nor its
      `commerce:waiting-room` feature name; `CommerceWaitingRoomGate`
      makes that check itself. `tests/WaitingRoomOnRegistrationTest.php`.

- [x] ADR-0047 point 4 (Task 11), Registration learns which cart lines were
      paid — a new `Listeners\RecordPaymentOnOrderLineSettled` consumes
      Commerce's `OrderLineSettled`, the counterpart of `OrderPaid` for a
      cart checkout's order (Task 11), which settles a mix of products and
      registrations that `OrderPaid` alone cannot tell apart: a
      `kind: 'registration'` line runs the same
      `Actions\RecordOrderPaidForRegistration` `RecordPaymentOnOrderPaid`
      already calls, keyed by the line's own `ref_id`; a `kind: 'product'`
      line is Commerce's own business and is ignored.
      `RecordPaymentOnOrderPaid` needed no code change to stop double-
      confirming a cart order — it already only ever acted on
      `settlesType === 'registration'`, so `'cart'` was already ignored;
      `OrderLineSettledTest.php` proves that explicitly rather than
      leaving it as an accident of the existing guard.
      `RegistrationController::store()`'s redirect to `commerce.cart.show`
      (written in Task 10, marked `->todo()` until this task registered
      the route) now runs for real.

- [x] ADR-0047 point 3 (Task 10), a cart that dies lets its ticket go — new
      `CancelPendingRegistrationForCart::handle(brandId, registrationId)`
      cancels the still-pending registration a dead cart line was holding
      (`payment_status: pending` and not already cancelled — idempotent
      either way, since a second `CartLineExpired`/`CartLineRemoved` for the
      same registration is a real possibility) through the existing
      `CancelRegistration`, which already releases whatever quota units it
      consumed. The new `CancelPendingRegistrationOnCartLineGone` listener is
      registered twice in `RegistrationServiceProvider::registerListeners()`,
      once each for `Konventix\Commerce\Events\CartLineExpired` and
      `CartLineRemoved` — the sanctioned cross-module import now runs in both
      directions (ADR-0047 "Consequences"), and only the Listener sees the
      Commerce event; the Action underneath takes scalars. Also:
      `RegistrationController::store()` now redirects to `commerce.cart.show`
      with an "added to your cart" flash when the brand's `commerce:shop`
      feature is on, the type settles `instant`, and the route exists
      (`Route::has()`, since Task 11 is what actually registers it) — no
      Commerce import needed to ask that question. The redirect's own test is
      written now and marked `->todo()` until Task 11 lands the route.

- [x] Registration ships "Registration Desk" (`registration.desk`:
      `ViewRegistrations`, `ApproveRegistrations`, `RejectRegistrations`,
      `CancelRegistrations`, `ExportRegistrations`) as its ADR-0045 default
      role set, declared beside `src/Enums/Permission.php` in
      `RegistrationServiceProvider::defaultRoles()` and materialized per
      brand by `permissions:sync` (and on module activation and brand
      creation, since Registration is always on).

- [x] Authorization for viewing, approving, and managing types and quotas
      goes through `Gate::authorize(Permission::X->value)` on each admin
      controller action, this project's actual pattern rather than a
      per-model Policy class; `permissions:sync` has picked up
      `src/Enums/Permission.php` all along, since it walks every module's
      enum.

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
- [x] Capability catalog in `src/Enums/Permission.php`.
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
- [x] `RegistrationOwnershipAnnounced` and `registration:announce-ownership`
      (design §13): a replay-safe backfill path for
      `admissions.buyer_user_id` on rows that predate that column —
      deliberately its own event rather than a replay of
      `RegistrationConfirmed`, which also drives the confirmation mail.
      Only a projection may ever listen to it.
- [x] `registration_event_summaries` now also carries the schedule
      (`starts_at`/`ends_at`/`timezone`) and the primary venue
      (`venue_label`/`venue_id`), projected with the same patch-not-replace
      care as Admission's table (design §13) — `handleDetailsUpdated()`
      cannot blank a schedule `handleCreated()` set, and
      `handleRescheduled()` cannot blank the name; unlike Admission's
      version, `handleRescheduled()` here only ever patches an existing
      row, since every event already has one by the time a reschedule can
      reach it. Feeds the (later) confirmation mail's calendar attachment
      via `Konventix\Platform\Calendar\CalendarFile`, without Registration
      ever importing Event.
