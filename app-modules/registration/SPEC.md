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
  | `grants` | admissions, roles, resource claims |

  Four fundamentally different processes run on this one model: visitor ticket
  = `public` / `none` / `instant` / `on_payment`; exhibitor = `public` /
  `required` / `invoice` / `on_approval`; speaker = `invite_only` / `none` /
  `free` / `on_submit`; crew = `invite_only` / `none` / `free` /
  `on_approval`.
- **`Registration`** — the contractual act itself. Carries the invoice
  recipient (which may be an organization rather than the submitting user), the
  registration status, and the answers given on the registration form.
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
  `RegistrationType`. An amendment consumes one unit per added admission, and
  cancellation releases the submission's unit *plus* every amended one.
- **The approval workflow** — submission, review, approval, rejection, and the
  record of who decided what and when.
- **Grant definitions** — how many admissions of which role, and which resource
  claims, a confirmed registration of a given type is entitled to. The
  definition lives here; the resulting admissions and bookings do not.
- **Invitation tokens** for `invite_only` types and link tokens for `link`
  visibility.
- **Cancellation and withdrawal** of the contractual act.
- The **price and tax-treatment declaration** on the `RegistrationType`,
  including the net-vs-gross display flag, which hangs on the type and not on a
  global setting (ADR-0006). Registration declares it; Commerce applies it.

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
| `RegistrationConfirmed` | The type's `confirmation_gate` has been passed — the one event that materializes entitlements | `registration_id`, `registration_type_id`, `event_id`, `brand_id`, `confirmed_at`, `grants` (list of `{kind, role, quantity, resource_type_id}` scalars) |
| `RegistrationSettlementRequested` | A registration on a `settlement` of `instant` or `invoice` needs an order. **Gate-dependent emission:** `on_payment` types emit at submission (paying must precede confirming), all other gates emit at confirmation — the two points partition, no double emission. A priced amendment emits a third time, keyed on itself. `external` never emits: money moves off-platform, and door payment is a Commerce POS concern (ADR-0018) | `registration_id`, `registration_type_id`, `event_id`, `brand_id`, `settlement`, `billing_party` (scalars incl. address, `country_code`, `vat_id`), `lines` (list of `{label, quantity, unit_amount_net, tax_class}` — the declared tax *class*, never a rate: Commerce resolves the rate and treatment, ADR-0017/ADR-0030), `price_display` (`net` / `gross`), `terms_days`, `requested_at`, `request_key` (the ULID of whatever requested settlement — the registration for the initial request, the amendment for a delta; Commerce's order idempotency key, ADR-0036) |
| `RegistrationAmended` | Grants are added to an already-confirmed registration (ADR-0036). Always emitted; the money side is separate and conditional, so a free amendment — crew that comes with an exhibitor's package — emits only this | `registration_id`, `amendment_id`, `registration_type_id`, `event_id`, `brand_id`, `grants` (**only the added** grants, same scalar shape as `RegistrationConfirmed`), `amended_at` |
| `RegistrationCancelled` | The contractual act is cancelled by organizer or registrant | `registration_id`, `cancelled_by_user_id`, `reason_code`, `cancelled_at` |
| `RegistrationPaymentStatusChanged` | The independent payment status field moves | `registration_id`, `brand_id`, `previous_status`, `status`, `changed_at` |
| `QuotaExhausted` | The last unit of a quota is consumed | `quota_id`, `event_id`, `brand_id`, `exhausted_at` |
| `QuotaReleased` | Units return to a quota after cancellation or expiry | `quota_id`, `released_units`, `released_at` |

## Consumes

Events from other modules that this module listens to, and the read models it
maintains from them.

| Event | Owning module | Why |
|---|---|---|
| `OrderPaid` | Commerce | The decisive input for `confirmation_gate: on_payment` — set payment status to paid and, only if the gate says so, confirm the registration (ADR-0006). Approval stays a precondition: on an `approval: required` type whose payment lands before review, the payment is recorded but confirmation waits for `ApproveRegistration` |
| `PaymentReceived`, `InvoiceOverdue` (proposed) | Commerce | Keep the payment status of invoice-settled registrations current without touching their confirmation status |
| `OrderCancelled`, `OrderRefunded` (proposed) | Commerce | Decide whether the contractual act is cancelled too, or only its settlement |
| `UserProfileUpdated` (proposed) | Identity | Maintain the registrant read model (display name, contact) used in registration lists |
| `UserAnonymizationRequested` (proposed) | Compliance | Hand the request to this module's `src/Gdpr/` handler |
| `EventCreated`, `EventDetailsUpdated`, `EventArchived` | Event | Maintain the event read model (`Models\EventSummary`, projected by `Listeners\ProjectEventSummary`) behind the type editor's event picker — name and slug only, plus whether the event is archived and should stop being offered |
| `ResourceBookingReleased` (proposed) | Resources | Release the matching resource claim when a booking is given up upstream |

## Capabilities

Declared in `src/Enums/Permission.php`. Roles that grant them are per-brand
data, not code (ADR-0013).

The catalog covers: viewing registrations, managing `RegistrationType`
definitions, managing quotas, approving and rejecting submissions, cancelling
registrations, issuing invitation tokens, and exporting registration lists.
Registration never references another module's `Permission` enum.

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
