# Registration — Specification

> Stable document. Describes what this module *is*. Task tracking lives in
> `TODO.md`.

## Purpose

Registration owns the **commercial and contractual act** of signing up for an
event: a person or an organization agrees to attend, exhibit, speak, or help,
under the terms of a `RegistrationType`. A Registration is a contract with an
invoice recipient and a status — it is not a person's ticket. One Registration
grants **N** access rights and resource claims (ADR-0005): an exhibitor
registration for "Musterfirma GmbH" produces one booth booking, five crew
admissions, ten visitor admissions, and one invoice. Registration decides *who
is entitled to what*; other modules materialize those entitlements.

## Owns

- **`RegistrationType`** — the offer, described by explicit axes instead of
  implicit assumptions (ADR-0005):

  | Axis | Values |
  |---|---|
  | `visibility` | `public` / `link` / `invite_only` |
  | `approval` | `none` / `required` |
  | `settlement` | `free` / `instant` / `invoice` / `external` |
  | `confirmation_gate` | `on_submit` / `on_approval` / `on_payment` |
  | `grants` | admissions, roles, resource claims, seating |

  Four fundamentally different processes run on this one model: visitor ticket
  = `public` / `none` / `instant` / `on_payment`; exhibitor = `public` /
  `required` / `invoice` / `on_approval`; speaker = `invite_only` / `none` /
  `free` / `on_submit`; crew = `invite_only` / `none` / `free` /
  `on_approval`. `sales_start`/`sales_end` are nullable columns on the type.
  The sales window is stored as instants, not ADR-0027 wall-clock times
  (#218).
- **`EntryCondition`** and the `registration_type_entry_condition` pivot
  (ADR-0066, D-REG-17/18) — what a reduced fare rests on: the sentence a
  registrant ticks (`assertion_text`, verbatim), the document the door will
  ask for (`document_hint`), and how it is meant to be checked
  (`verification`: `self_declared` / `document_at_door` /
  `operator_checked`). Brand-wide and reusable, with an optional `event_id`
  pinning a one-off condition to one event; the stable `key` is what travels
  to the door and onto the ticket, the ULID is what a Compliance policy
  binding points at. Conditions are **archived, never deleted**, and the set
  on a type stays editable after the type has sold — `entryConditions` is
  deliberately **not** one of `GuardsTypeConfiguration::frozenAxes()`
  (D-REG-19). `operator_checked` is advisory and fills a worklist; a blocking
  check is `approval: required` on the type (D-REG-22).
  `registration_types.alternative_registration_type_id` is nullable and used
  for exactly one thing: the "buy the regular ticket instead" link beside a
  refused submission (D-REG-21).
- **`EntryConditionAssertion`** — the evidence: one row per
  `(registration, condition)` whatever the registration's `quantity`
  (D-REG-20), carrying `assertion_text_snapshot` and `verification_snapshot`
  as they stood when the declaration was made, plus what was later decided
  about it (`asserted` / `verified` / `refused` / `waived`, with a bounded
  `reason_code` and `decided_via: console|door`). The snapshot is why the
  condition may be reworded freely: evidence may not change under the person
  who gave it. A condition added to a live type writes **no** row for the
  registrations that already exist — the console shows *not asserted*
  instead — and removing one from a type leaves the historic rows standing.
  A refusal records a fact: it never revokes an admission and never reaches
  Commerce.
- **`Registration`** — the contractual act itself. Carries the invoice
  recipient (which may be an organization rather than the submitting user), the
  registration status, the answers given on the registration form, and
  `quantity`: how many units of the offer this act buys. Quantity is a
  Registration fact chosen at submission and never edited afterwards —
  not in the cart, not on the order — bounded by the type's
  `max_quantity_per_registration` (nullable, null means one, so a type opts
  in) and by what the type's quotas still have left. It multiplies the quota
  units held, the settlement line's quantity and every grant's own
  multiplicity. It is edited in exactly one direction and by exactly one
  Action: `ReduceRegistrationQuantity` (D-REG-15) takes `1 <= N < quantity`
  units back off a **confirmed** registration, releases that many units to
  every quota the type draws on one at a time (so the waitlist, which stays
  one unit per entry, makes N offers rather than one), writes a
  `RegistrationQuantityReduction` row whose ULID is the idempotency key
  every other module keys on, and emits `RegistrationQuantityReduced`.
  Removing the last unit is refused — that is a cancellation, and
  `CancelRegistration` owns it — and a reduction never touches an
  amendment's own units, so a later cancellation still releases each unit
  exactly once. The capability is `registration.reduce-registration-quantity`,
  deliberately not part of `registration.desk`: the reduction moves money
  through Commerce without the operator ever opening the order. Buyer
  self-service is a later item.
  `registrant_user_id` is required, never nullable: **D-REG-08**
  (`docs/inventory/decision-log.md` Round 5) settled that registrants must
  hold an account in Phase 1 — guest registration (a registrant with no
  Identity account) is deferred, since it needs a non-account subject
  identity from Compliance and its own ADR. The public submission routes
  enforce this with `auth` middleware in front of the Action's own guard.
- **Two independent status fields.** `confirmation_gate` decouples confirmation
  from payment (ADR-0005): registration status and payment status are separate
  fields and neither implies the other. An invoice-settled exhibitor is
  confirmed on approval while its payment is still open and monitored through
  due dates and dunning; a `free` crew registration has no payment story at
  all.
- **`RegistrationAmendment`** — one delta applied to an already-confirmed
  registration (ADR-0036): the grants that were added and, when the addition
  costs money, the lines charged for them. Amendments **add grants only** — a
  type change re-opens settlement, approval and gate at once, so that stays
  cancel-and-recreate. The row's ULID is load-bearing outside this module: it
  is Commerce's order idempotency key and Admission's delta issuance key, which
  is what lets one registration carry N orders without a replay creating an
  N+1st. Money is conditional — added crew badges come with an exhibitor's
  package, so a free amendment materializes admissions without an order, an
  invoice, or any disturbance to the immutable original (ADR-0031).
- **`Quota`** — a standalone entity, not a column on a type. Quotas are
  consumed and released by registrations and may be referenced by more than one
  `RegistrationType`. A submission consumes `registrations.quantity` units
  (one, unless the type opted into a larger
  `max_quantity_per_registration`), an amendment consumes one unit per added
  admission, and cancellation releases the submission's own `quantity`
  *plus* every amended one — consume and release are symmetric or capacity
  leaks. A submission asking for more units than remain is refused as a
  `quantity` form error naming what is left; a submission that finds
  *nothing* left still queues on the waitlist, which keeps its one-unit
  semantics (a promotion holds one unit and a conversion buys one).
  `resource_type_id` (design §12b, #282) is an optional, nullable link to a
  Resources seat category — a plain ULID, no FK (ADR-0014) — set on the quota
  form so one quota can be a category's ceiling: `AdjustQuotaOnPlaceSupplyChanged`
  is the only reader.
  **Every quota lock is taken in `quotas.id` order** (#233), declared once on
  `RegistrationType::quotas()` rather than at each of the six call sites that
  run `->quotas()->lockForUpdate()->get()`. Unordered, the rows came back in
  pivot-scan order, which differs per type: two concurrent submissions on two
  types sharing two quotas locked them in opposite orders and deadlocked.
- **The approval workflow** — submission, review, approval, rejection, and the
  record of who decided what and when.
- **Grant definitions** — how many admissions of which role, which resource
  claims, and which seating (categories, mode, number of places) a confirmed
  registration of a given type is entitled to. The definition lives here; the
  resulting admissions, bookings and seat claims do not. A grant's
  `quantity` is the per-unit multiplicity; the registration's own quantity
  multiplies it **once**, in `ConfirmRegistration`'s `RegistrationConfirmed`
  payload, so Admission, Resources and Community read the final count and
  never learn that a registration has a quantity at all. `grants` is one of the
  axes `GuardsTypeConfiguration` freezes once a type has registrations
  (ADR-0060), so a seating grant is an operational ordering constraint, not
  something added after the fact: it must be on the type before the type sells
  anything, never appended once registrations exist.
- **Invitation tokens** for `invite_only` types and link tokens for `link`
  visibility.
- **Cancellation and withdrawal** of the contractual act.
- The **price and tax-treatment declaration** on the `RegistrationType`,
  including the net-vs-gross display flag, which hangs on the type and not on a
  global setting (ADR-0006). Registration declares it; Commerce applies it.
  Every public surface of this module — the `event.landing` slot, the
  `tickets-for-event` block, the `shop.index` slot and the registration form —
  shows what the offer *costs* beside what it declares (#234, D-COM-20):
  `PublicOffers::endPricesFor()` asks Platform's
  `Pricing\EndPriceCalculator`, which Commerce binds, and nothing here
  imports `Commerce\Tax\*` or learns a tax rate. Where nothing answers, the
  declared net amount is shown with its "excl. VAT" label.
  A **waitlist entry pins the declaration it was created with** (D-0.8.2-2,
  #8) beside the billing party it already snapshotted: a promotion settles
  at the price the registrant saw when they joined the queue, not at what
  the type costs weeks later. The *whole* value object is pinned, tax class
  and display flag included — pinning the amount alone would charge a
  gross-displayed type a gross nobody was shown, and pinning the class
  freezes no tax law, because the rate for a class and the treatment (§19,
  reverse charge) are both resolved in Commerce at settlement time
  (ADR-0017, ADR-0030). A `free` type pins nothing, and an entry with no pin
  — every row queued before the column existed — falls back to the type's
  current declaration.

## Does not own

| Nearby concept | Owned by |
|---|---|
| A person's access right, badge, ticket token, check-in | **Admission** — Registration grants admissions; it never issues, validates, or scans them |
| Cart, `Order`, `Invoice`, payment records, dunning, refunds, PSP settlement | **Commerce** — Registration learns payment outcomes from events, never from Commerce models (ADR-0006) |
| Discount vouchers and their redemption | **Commerce** — distinct from Registration's invitation tokens, which gate access to an `invite_only` type rather than a price |
| The booth, seat, room, or device itself, its inventory and its booking record | **Resources** — a registration expresses a *claim*; the booking is created and held there |
| The event, its dates, and its venue | **Event** |
| The user account, profile, and authentication of the registrant | **Identity** — Registration keeps a registrant read model populated from Identity's events (ADR-0004) |
| Platform authorization roles and permission grants | **Platform** plus per-brand role data (ADR-0013). A granted role such as `exhibitor_crew` is an admission label, not a Gate role |
| Confirmation mails, reminders, and any message delivery | **Messaging** |
| Consent records, policy acceptance, GDPR export and deletion orchestration | **Compliance** — Registration contributes its own `src/Gdpr/` exporter |
| Shift planning for crew whose registration was confirmed | **Staffing** |
| Brand resolution, theming, navigation registry, module activation | **Platform** |

## Public contract (events)

Events are the only way other modules may react to this one (ADR-0004).
Payloads carry ULIDs and scalars — never Eloquent models.

| Event | Emitted when | Payload |
|---|---|---|
| `RegistrationSubmitted` | A registration form is accepted and persisted | `registration_id`, `registration_type_id`, `event_id`, `brand_id`, `registrant_user_id`, `settlement`, `confirmation_gate`, `submitted_at` |
| `RegistrationApproved` | A reviewer approves a registration on an `approval: required` type | `registration_id`, `approved_by_user_id`, `approved_at` |
| `RegistrationRejected` | A reviewer rejects it | `registration_id`, `rejected_by_user_id`, `reason_code`, `rejected_at` |
| `RegistrationConfirmed` | The type's `confirmation_gate` has been passed — the one event that materializes entitlements | `registration_id`, `registration_type_id`, `event_id`, `brand_id`, `registrant_user_id`, `confirmed_at`, `grants` (list of `{kind, role, quantity, resource_type_id, resource_type_ids, seating_mode}` scalars; `resource_type_ids` and `seating_mode` are set on `kind: seating` only). `quantity` is the grant's own multiplicity **times the registration's quantity** — multiplied here and nowhere else |
| `RegistrationSettlementRequested` | A registration on a `settlement` of `instant` or `invoice` needs an order. **Gate-dependent emission:** `on_payment` types emit at submission (paying must precede confirming), all other gates emit at confirmation — the two points partition, no double emission. A priced amendment emits a third time, keyed on itself. `external` never emits: money moves off-platform, and door payment is a Commerce POS concern (ADR-0018) | `registration_id`, `registration_type_id`, `event_id`, `brand_id`, `settlement`, `billing_party` (scalars incl. address, `country_code`, `vat_id`), `lines` (list of `{label, quantity, unit_amount_net, tax_class}` — the declared tax *class*, never a rate: Commerce resolves the rate and treatment, ADR-0017/ADR-0030. The initial request is one line at the registration's own `quantity` and the type's **per-unit** net, so the cart and the order both multiply; an amendment sends the delta's own lines), `price_display` (`net` / `gross`), `terms_days`, `requested_at`, `request_key` (the ULID of whatever requested settlement — the registration for the initial request, the amendment for a delta; Commerce's order idempotency key, ADR-0036), `saleStartsAt`/`saleEndsAt` (nullable ISO-8601 instants. Null means *not window-bound*: the window travels only with a fresh registration, never with a waitlist conversion, a post-approval confirmation or a priced amendment, #218) |
| `RegistrationAmended` | Grants are added to an already-confirmed registration (ADR-0036). Always emitted; the money side is separate and conditional, so a free amendment — crew that comes with an exhibitor's package — emits only this | `registration_id`, `amendment_id`, `registration_type_id`, `event_id`, `brand_id`, `registrant_user_id`, `grants` (**only the added** grants, same scalar shape as `RegistrationConfirmed`), `amended_at` |
| `RegistrationCancelled` | The contractual act is cancelled by organizer or registrant | `registration_id`, `cancelled_by_user_id`, `reason_code`, `cancelled_at` |
| `RegistrationQuantityReduced` | An operator took units off a **confirmed** registration (D-REG-15) — the subtractive counterpart of `RegistrationAmended`, and the only partial cancellation there is | `registration_id`, `reduction_id` (the idempotency key downstream modules key on), `registration_type_id`, `event_id`, `brand_id`, `registrant_user_id`, `removed_units`, `previous_quantity`, `new_quantity`, `removed_grants` (**only what is taken away**, same scalar shape as `RegistrationConfirmed.grants` and already multiplied by `removed_units`), `reason_code`, `reduced_by_user_id`, `reduced_at`. **Consumer ordering is part of the contract:** Admission's listener is synchronous and throws when fewer than `removed_units` admissions are free, Commerce's is queued `afterCommit` — so a refused revocation rolls the whole reduction back and refunds nothing |
| `RegistrationPaymentStatusChanged` | The independent payment status field moves | `registration_id`, `brand_id`, `previous_status`, `status`, `changed_at` |
| `QuotaExhausted` | The last unit of a quota is consumed | `quota_id`, `event_id`, `brand_id`, `exhausted_at` |
| `QuotaReleased` | Units return to a quota after cancellation or expiry | `quota_id`, `released_units`, `released_at` |
| `EntryConditionCreated` / `EntryConditionUpdated` / `EntryConditionArchived` | An operator defines, changes or archives an entry condition. All three are `AuditableEvent` — binding a fare to a requirement is a privileged act | `entry_condition_id`, `brand_id`, `key`, `name`, `verification` |
| `RegistrationTypeEntryConditionsChanged` | The set of conditions a type demands changed. A **full state**, not a delta, and nothing retroactive follows from it (D-REG-19) | `registration_type_id`, `brand_id`, `entry_condition_ids` (in render order) |
| `RegistrationEntryConditionAsserted` | A registrant declared that they meet a condition — one per condition the type demanded at submission | `assertion_id`, `registration_id`, `entry_condition_id`, `entry_condition_key`, `brand_id`, `subject_user_id`, `verification`, `asserted_at` |
| `RegistrationEntryConditionDecided` | Somebody verified, refused or waived a declaration, at the console or at the door | `assertion_id`, `registration_id`, `entry_condition_key`, `brand_id`, `state`, `decided_by_user_id`, `decided_via`, `reason_code`, `decided_at` |
| `RegistrationConsentGiven` | A registrant accepted the legal texts on the registration form (D-CPL-10) — Registration's half of the declaration contract, beside Commerce's `CheckoutConsentGiven` | `brand_id`, `subject_id`, `capture_context`, `accepted_at`, `binding_context` (an opaque bag of scalars this module builds and never interprets) |
| `RegistrationOwnershipAnnounced` | `registration:announce-ownership` replays it for every registration (design doc §13, 2026-09-11 amendment) | `registration_id`, `brand_id`, `registrant_user_id` — a registration's buyer, restated for backfill only — never re-emits `RegistrationConfirmed`'s mail-triggering payload |
| `RegistrationStandingAnnounced` | `registration:announce-standing` replays it for every registration (competition design §5.1, final review fix wave B) | `registration_id`, `event_id`, `brand_id`, `registrant_user_id`, `confirmed` (the status is `confirmed` now) — restated for backfill only, never auditable, and only a projection listens; Competition's eligibility read model is the first |

## Consumes

Events from other modules that this module listens to, and the read models it
maintains from them.

| Event | Owning module | Why |
|---|---|---|
| `OrderPaid` | Commerce | The decisive input for `confirmation_gate: on_payment` on a **direct** order (`settles_type` other than `cart`) — set payment status to paid and, only if the gate says so, confirm the registration (ADR-0006). Approval stays a precondition: on an `approval: required` type whose payment lands before review, the payment is recorded but confirmation waits for `ApproveRegistration`. A `settles_type: 'cart'` order no longer confirms from this event alone — see `OrderLineSettled` |
| `OrderLineSettled` (ADR-0047 point 4, Task 11) | Commerce | The counterpart of `OrderPaid` for a cart checkout, whose order settles a mix of products and registrations that `OrderPaid` alone cannot tell apart: a `kind: 'registration'` line runs the same `RecordOrderPaidForRegistration` `OrderPaid` does, keyed by the line's own `ref_id`; a `kind: 'product'` line is Commerce's own business and is ignored |
| `PaymentReceived`, `InvoiceOverdue` (proposed) | Commerce | Keep the payment status of invoice-settled registrations current without touching their confirmation status |
| `PaymentReversed`, `OrderLineReversed` (ADR-0057 point 5) | Commerce | Settled money left again — a Rücklastschrift, a lost dispute, an external refund. `payment_status` goes back to `pending` and `RegistrationPaymentStatusChanged` says so, while `status` stays `confirmed` and the quota stays held: ADR-0005's two independent fields exist for exactly this, and a bank error two days before the event does not throw a confirmed person out. The per-line event is a cart order's, exactly as `OrderLineSettled` is to `OrderPaid`. Registration never reads a reversal row |
| `OrderCancelled`, `OrderLineCancelled`, `OrderRefunded` (ADR-0057 point 5) | Commerce | The order no longer stands at all, so the contractual act ends too: `OrderCancelled` with reason `reversal_unpaid` (the repayment deadline passed and Commerce's sweep cancelled) cancels through `CancelRegistration` with reason `payment_reversed`, and `OrderRefunded` with `whole: true` does the same with reason `order_refunded` — the gap ADR-0037 left open. `OrderLineCancelled` is the per-line counterpart for a cart order, whose own `OrderCancelled` cannot say which lines it undid, exactly as `OrderLineSettled` is to `OrderPaid`. `CancelRegistrationOnOrderLineCancelled` now maps two reasons — `reversal_unpaid` to `payment_reversed` and `order_refunded` (a cart order's whole refund) to `order_refunded` — the same codes the order-level listener writes, so the audit column does not depend on which kind of order paid. Either way the quota is released and the waitlist promotes. Any other cancellation reason, a `product` line, and a partial refund leave the registration standing |
| `RegistrationLinePlacedInCart` | Commerce | #210: with `commerce:shop` on, an `instant` settlement becomes a cart line instead of an order (ADR-0047 point 3). `RecordCartHoldOnLinePlaced` stamps `registrations.cart_hold_expires_at` from the event so the confirmation mail can name the cart and its reservation without reading `cart_lines` (ADR-0004) |
| `CartLineExpired`, `CartLineRemoved` | Commerce | ADR-0047 point 3: a registration-kind cart line that dies — its twenty-minute reservation lapsed, or the person removed it and let #219's undo window close — cancels the still-pending registration it was holding and releases whatever quota units it consumed, through the existing `CancelRegistration`. The reason comes from the trigger (#220, `Enums\CartHoldEnded`): `CartLineExpired` writes `cart_expired`, `CartLineRemoved` writes `cart_line_removed`, and the cancellation mail says the buyer removed it rather than that it expired. Guarded on `payment_status: pending`, not already cancelled — and, since #211, **not `confirmed`**: a confirmed registration whose immediate payment sat in the cart is left standing (ADR-0057 point 5), logged, and counted as `confirmed_registrations_kept_total`; submitted and approved ones expire as before. Only a `registration`-kind line concerns this module; a `product` line is Commerce's own business |
| `UserProfileUpdated` | Identity | Maintain the registrant read model (`Models\Registrant`: display name, contact, and — ADR-0064 §6 — `username` and `listed`, the handle a name on the attendee list is and the person's opt-in to be named there) used in registration lists and by `Attendees\PublicAttendees` |
| `EventAttendeeListPolicyChanged` | Event | Project the per-event attendee-list switch onto `EventSummary.attendee_list_visibility` (`off`/`members`/`public`, ADR-0064): the `event.landing` card `registration/EventAttendees` and `GET /events/{slug}/attendees` show the count under the switch alone and names only with the registrant's opt-in — confirmed registrations, one entry per person, username only, profile link only where Community is active |
| `UserAnonymizationRequested` (proposed) | Compliance | Hand the request to this module's `src/Gdpr/` handler |
| `EventCreated`, `EventDetailsUpdated`, `EventAnnounced`, `EventPublished`, `EventArchived`, `EventRescheduled`, `VenueAssigned`, `VenueUpdated`, `VenueUnassigned` | Event | Maintain the event read model (`Models\EventSummary`, projected by `Listeners\ProjectEventSummary`) behind the type editor's event picker and behind `/shop`: name, slug, `visibility`, `public_since` (set by `EventAnnounced`/`EventPublished`, never cleared), `archived_at`, and — design doc §13, 2026-09-11 amendment — `starts_at`/`ends_at`/`timezone` and the primary venue's `venue_label`/`venue_id`, feeding the confirmation mail's calendar attachment (`Konventix\Platform\Calendar\CalendarFile`) without this module ever importing Event, and `banner_media_id` (#292, carried by `EventCreated`/`EventDetailsUpdated`, null clears it) which heads every lifecycle mail. Together `public_since IS NOT NULL AND visibility = 'public' AND archived_at IS NULL` is `Event::scopeListedPublicly()` — the rule a *listing* surface owes, stricter than `isVisibleOnLanding()` — which is what keeps a draft, an unlisted or an archived event's offers off `/shop` (#240). `EventUnpublished` is deliberately **not** projected: it returns the event to `Announced`, where its landing page still sells. `EventDetailsUpdated` carries no times and `EventRescheduled` carries no name/slug, so each patches only its own fields — neither erases what the other set — and, unlike Admission's copy of this same table, `handleRescheduled()` here never creates a row: Registration is a Core module, so every event already has one from `handleCreated()` by the time any reschedule can reach it. Only the *primary* venue is projected (`VenueAssigned.isPrimary`); `VenueUpdated` (no `eventId`) patches every row pointing at the renamed venue, and `VenueUnassigned` clears `venue_label`/`venue_id` only when it names the venue this row currently points at |
| `ResourceBookingReleased` (proposed) | Resources | Release the matching resource claim when a booking is given up upstream |
| `PlaceSupplyChanged` (design §12b, #282) | Resources | A category's bookable supply moved for a reason that is not a sale — blocked/unblocked, or reserved for or released from the organisation. Publishing or unpublishing a plan does **not** fire this event (#282 correction, §13 of the design doc) — a plan's publish state is not a supply move, and treating it as one could zero out sales already recorded. `AdjustQuotaOnPlaceSupplyChanged` finds the quota whose `resource_type_id` matches the payload's category and reuses `ConsumesQuotas`/`ReleasesQuotas`' own per-quota consume/release (same lock, same `QuotaExhausted`/`QuotaReleased` emission), brand taken from the payload rather than ambient context, idempotent per `changeId` (`quota_supply_change_receipts`), a no-op when no quota names the category |

## Capabilities

Declared in `src/Enums/Permission.php`. Roles that grant them are per-brand
data, not code (ADR-0013).

The catalog covers: viewing registrations, managing `RegistrationType`
definitions, managing quotas, approving and rejecting submissions, cancelling
registrations, reducing a confirmed registration's quantity (D-REG-15),
defining entry conditions and judging a declaration of one (D-REG-24 —
`ManageEntryConditions` and `VerifyEntryConditions`, two capabilities because
writing the terms of an offer and judging a document are different jobs;
only the judging one is part of `registration.desk`), issuing invitation
tokens, and exporting registration lists.
Registration never references another module's `Permission` enum.

## Entry conditions, end to end (ADR-0066, round 4 Task 31)

The surfaces the model of §"Owns" is reached through:

- **Console** — `/admin/entry-conditions` (index, editor, archive) behind
  `registration.manage-entry-conditions`. The editor carries a character
  budget on the assertion text and a live preview rendering the registrant's
  own checkbox component, and states that `operator_checked` is advisory: it
  fills a worklist and never gates a confirmation, for which the axis is
  `approval: required` on the type (D-REG-22).
- **Type editor** — an *Entry conditions* card beside Grants: an ordered
  multi-select with each condition's assertion text inline, plus the optional
  `alternative_registration_type_id` pointer. `entryConditions` is **not**
  one of `GuardsTypeConfiguration::frozenAxes()` (D-REG-19); saving emits
  `RegistrationTypeEntryConditionsChanged`, and on a type that has
  registrations the card warns first, naming how many of them a newly added
  condition leaves *not asserted* and how many historic declarations a
  removal leaves standing.
- **Public form** — `RegistrationFormData` carries
  `entryConditions: list<PublicEntryConditionData>` and the alternative
  type's id and name. One required checkbox per condition, the assertion text
  verbatim in a real `<label>`, the document hint via `aria-describedby`
  (ADR-0055, ADR-0008). `SubmitRegistrationData` gains
  `acceptedEntryConditionIds` and `acceptedLegalTexts`.
- **Submission** — `SubmitRegistration::guardEntryConditions()`, per type and
  beside `guardBillingParty()`: a missing declaration is a 422 keyed
  `entryConditions.{id}`, rendered beside the "buy the regular ticket
  instead" link where the type names an alternative (D-REG-21). Inside the
  existing transaction it writes one assertion per declared condition with
  both snapshots, emits one `RegistrationEntryConditionAsserted` each and one
  `RegistrationConsentGiven` carrying the binding context. A **waitlist
  conversion writes none**: the queue holds a billing snapshot, not a
  declaration, so its registration shows *not asserted* like any condition
  added after the fact.
- **Verification** — `Actions\DecideEntryConditionAssertion` (verify / refuse
  / waive, bounded `reason_code`, `decided_via`) behind
  `registration.verify-entry-conditions`, emitting
  `RegistrationEntryConditionDecided`. The registration detail
  (`/admin/registrations/{registration}`) lists declarations, *not asserted*
  rows for conditions added later and *no longer required* rows for
  conditions since removed; the registrations index gains an entry-condition
  state filter which is the `operator_checked` worklist. Every decision is
  advisory: a refusal records a fact and never cancels, revokes or reaches
  Commerce.
- **Privacy** — `Gdpr\RegistrationGdprHandler` exports assertions as
  `{condition name, asserted text, asserted_at, state, decided_at}` — the
  snapshot, never the condition's current wording — and anonymizes
  `subject_user_id`/`decided_by_user_id` with the registration, keeping the
  tombstone, idempotently. `Listeners\PruneEntryConditionAssertions`
  discharges the retention rule `registration.entry-condition-assertions` on
  the registration's own `EventConcluded` clock (D-REG-23), never the
  ten-year bookkeeping floor. An assertion reaches no attendee list
  (ADR-0064), no public profile and no export that is not the subject's own.

## Observability

`Metrics\RegistrationMetrics` (ADR-0059, ADR-0066 §10):
`entry_condition_assertions_total{brand, verification}` and
`entry_condition_decisions_total{brand, state, source}`, recorded by
`Listeners\Metrics\RecordEntryConditionMetrics` on this module's own events.
The condition's `key` rides on both events and is **never** a label: an
operator-defined key is unbounded cardinality (T-11). Both feed the *Consent
and conditions* row of the platform dashboard, and the second one feeds
`KonventixEntryConditionRefusalsAtDoor`.

## Mail

ADR-0048 point 3: the module that owns an event owns its mail — every key
below is a Registration listener on Registration's own event, calling
Messaging's `SendMessage` (never a Messaging-owned trigger). The five
lifecycle keys share one payload, `Data\RegistrationMailData`, built by the
shared `Mail\RegistrationMailer`; `nextStep`, `reason` and `cancelReason`
ride as codes rather than precomputed sentences, since `SendMessage`
resolves the recipient's locale only when it actually sends — a
PHP-computed German sentence would be wrong for an `en` recipient. Every
template's body branches on those codes with whitespace-safe
`@if`/`@elseif` (`.ai/rules/app-modules-src.md`); nothing else about a body
is conditional. No key here declares a topic — these are the record of a
contractual act's own lifecycle, not a notification a recipient can mute.

| Key | Trigger | Recipient | Topic |
|---|---|---|---|
| `registration.waitlist-offer` | `PromoteFromWaitlist::notify()` when a waitlist entry is offered a freed quota unit (D-REG-12) | The waitlisted registrant (`registrant_user_id`) | — |
| `registration.submitted` | `Listeners\MailOnRegistrationSubmitted` on `RegistrationSubmitted` — skipped when the type auto-confirms at submission (`approval: none`, `confirmation_gate: on_submit`): `RegistrationConfirmed` fires in the same request and `registration.confirmed` is already the receipt | The registrant | — |
| `registration.approved` | `Listeners\MailOnRegistrationApproved` on `RegistrationApproved` | The registrant | — |
| `registration.rejected` | `Listeners\MailOnRegistrationRejected` on `RegistrationRejected` | The registrant | — |
| `registration.confirmed` | `Listeners\MailOnRegistrationConfirmed` on `RegistrationConfirmed` | The registrant | — |
| `registration.cancelled` | `Listeners\MailOnRegistrationCancelled` on `RegistrationCancelled` | The registrant | — |
| `registration.quantity-reduced` | `Listeners\MailOnRegistrationQuantityReduced` on `RegistrationQuantityReduced` (D-REG-15) — the one lifecycle mail whose subject is not an end state, so it names what remains as well as what went | The registrant | — |

An `on_approval`-gated type still sends both `registration.approved` and
`registration.confirmed` for the same approval — two mails in one request
by design, since they say different things (a reviewer's decision, then the
entitlement itself), unlike the on-submit case above where both mails would
say the same thing.

## Activation

**Core tier: always active.** Every brand needs Registration, so no Pennant
feature gates it and none should be introduced — a brand without registration
has no event business. The module still self-registers routes, policies,
listeners, navigation, and its GDPR exporter through
`RegistrationServiceProvider` at boot like any other module (ADR-0003);
"always active" describes the request-time gate, not the registration step.

Registration is also the module a brand configures first: types and quotas must
exist before Admission, Commerce, or Resources have anything to work on.

## Open questions

1. ~~**Where the price lives.**~~ **Settled by ADR-0017:** `RegistrationType`
   carries the price declaration as a value object (net amount, tax rate,
   net-vs-gross display); Commerce computes and snapshots. A future catalogue
   would project into the value object, not replace it.
2. ~~**Invitation tokens vs. vouchers.** Registration's invitation tokens gate
   access to an `invite_only` type; Commerce's vouchers change a price. The
   exhibitor example in ADR-0005 (ten visitor admissions handed out as voucher
   codes) sits between the two — decide which module issues those codes.~~
   **Decision log Round 6 (D-COM-02):** Registration owns invitation tokens
   (access); Commerce owns vouchers (price). Exhibitor visitor codes are
   invitation tokens granting a free type — Registration issues them.
3. **Quota composition — partially settled.** Multiple quotas per
   `RegistrationType` via the `registration_type_quota` pivot is shipped
   (`SubmitRegistration::consumeQuotas()`); **shared pools referenced by
   several types are the model** — a quota is not scoped to one type. ~~Still
   open: how a registration consuming several quotas behaves when only one of
   them is exhausted (all-or-nothing today, see `TODO.md`).~~ **Decision log
   Round 7 (D-REG-12):** all-or-nothing across a type's quotas — any
   exhaustion waitlists the whole submission. FIFO promotion on
   `QuotaReleased` with time-limited offers (48h default); entry states
   queued → offered → converted/expired/withdrawn.
4. ~~**Waiting lists.** Whether an exhausted quota produces a waiting-list
   entry in this module, or whether waiting lists are a separate concept.~~
   **Decision log Round 6 (D-REG-04, user override):** a Registration-owned
   waitlist ships at release — quota exhaustion produces a waitlist entry
   here. Resources has no queue of its own and points at this one.
5. ~~**Deferred attendee data.** For group and B2B registrations the N people
   are unknown at submission time. Which module holds the unassigned-admission
   placeholder — Registration or Admission — and when the names are
   collected.~~ **Settled by ADR-0032:** Admission holds the holder-less
   placeholders, issued on confirmation; names attach later via claim links.
   Registration still owns the grant count that produces them.
6. ~~**Amendment.** Adding admissions to an already-confirmed registration, or
   changing its type, without cancelling and re-creating it, including the
   effect on an invoice that has already been issued.~~ **Settled by ADR-0036
   (decision log Round 7, D-REG-10):** delta orders — added grants create a
   new order/invoice against the same registration; the original invoice is
   never touched. Type changes stay cancel-and-recreate.
7. ~~**Transfer.** Whether a Registration may change its invoice recipient
   after confirmation.~~ **Settled by ADR-0036 (decision log Round 7,
   D-REG-11):** the billing party is editable until the first invoice is
   issued and frozen after; a post-invoice change is a capability-gated
   credit-note + re-issue flow.
