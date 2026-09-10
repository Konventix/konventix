# Resources — Specification

> Stable document. Describes what this module *is*. Task tracking lives in
> `TODO.md`. Seating (plans, places, seat claims, the picker) was merged
> into this document from `docs/superpowers/specs/2026-09-10-seating-design.md`;
> ADR-0060 records why it lives here.

## Purpose

Resources owns everything an event has a limited amount of and that somebody can
hold for a period of time: seats, rooms, booth space, loanable devices. It
generalizes LanCore's Seating domain, and the generalization is the point — a
seat is **one resource type with a time dimension**, not a special case with a
booking layer bolted on. Every resource type goes through the same entity, the
same availability model, and the same booking state machine. A booking always
occupies an interval, even when that interval is "the whole event", which is
how LanCore's implicit seat model reappears here without its assumptions.

Domain language (ADR-0060): a **Place** is a unit-mode `Resource` projected from
a plan; a **Kategorie** (category) is a `ResourceType`; a seat booking is a
`ResourceBooking`; a registrant's entitlement to pick is a `ResourceClaim`.

## Owns

- **ResourceType** (= Kategorie) — what kind of thing is bookable and the rules
  that apply: capacity mode (`unit` or `quantity`, ADR-0034), `hold_minutes`
  for clocked holds, and for seat categories the presentation the picker
  needs: `token` (a semantic colour token, never a hex), `symbol` (a lucide
  icon key, mandatory so state is never conveyed by colour alone), a
  description, a sort order and a default shape.
- **Resource** (= Place) — one bookable unit, belonging to an event and
  referencing a venue by ULID. A plan-placed resource carries `plan_id`,
  `ref` (unique per plan), the derived `zone_id`/`group_id`, `accessible`,
  `blocked` (operator lock), `neighbors` and soft deletion, and its
  `is_published` is derived from the plan on every save. Plan-less resources
  (booths, devices) keep the manual flag. `Resource::scopeBookable()`
  (published, not blocked) is the count an operator sizes a seat category's
  Registration quota against (#282). The ceiling itself is a Registration
  `Quota`, not a Resources concept — Resources has no quota of its own to
  enforce — but blocking a place or reserving it for the organisation
  *moves* that ceiling's ledger exactly as a sale would: `PlaceSupplyChanged`
  carries the category and how many places changed in which direction, and
  Registration consumes or releases the linked quota's unit from it.
  Unblocking or releasing already drains the waitlist through the existing
  `QuotaReleased` path, so a freed seat is offered to the queue with no
  further work here.
- **Plan and plan versions** — `resource_plans` holds one hall plan per
  event and name: dimensions in millimetres, optional raster background,
  display unit, snap step, a `version` incremented per save, `published_at`,
  and the plan **document** (`konventix.plan/v1`, `PlanDocumentData`) with
  zones, groups, places, fixtures and annotations. `resource_plan_versions`
  keeps one row per save (source `editor|import|copy`), pruned to the last
  50 per plan.
- **Geometry projection** — `SavePlan` projects every `place` element of the
  document into a `resources` row whose id equals the element id, recomputes
  the derived columns, soft-deletes removed places and detects conflicts
  with occupied places. The document is the only home of geometry; booking
  logic reads only the projection and never the document.
- **ResourceClaim** — the Resources-owned snapshot of a `seating` grant
  (decision log D-EVT-06): event, registration, `registrant_user_id`,
  amendment, the category ids the registrant may choose from, the seating
  mode (`pick|assign`), the maximum quantity and a state
  (`unplaced|placed|released`). An unplaced claim is a row, never a fake
  booking.
- **ResourceBooking** — a claim on a resource for a half-open interval by a
  holder referenced by ULID (`registration` or `operator`), plus its state:
  held, confirmed, released, cancelled, expired. Seat bookings carry
  `claim_id`, `amendment_id` and a `label` snapshot.
- **Two hold kinds** (ADR-0060 narrows the earlier "every hold has a
  deadline"): a *clocked* direct hold carries `hold_expires_at` and is
  released by `resources:expire-holds`; a registrant's seat booking is held
  and confirmed in one transaction and never has a clock.
- **Conflict prevention** — two bookings never overlap on the same resource
  beyond its capacity. In unit mode the PostgreSQL exclusion constraint
  `resource_bookings_no_overlap` decides (ADR-0020); in quantity mode a
  locked, summed check inside the Action does (ADR-0034). A lost race is
  `ResourceUnavailableException` (23P01) or a deadlock (40P01), translated by
  the Action into a domain or validation error.
- **Claim fulfilment** — turning `resource_claim` grants into first-fit
  bookings (`n` unit bookings for quantity `n`, ordered by plan, zone, group,
  ref) and `seating` grants into claims, idempotently per
  `(registration_id, amendment_id)`; releasing all of it when the
  registration is cancelled or rejected.
- **Event window read model** — `resource_event_windows`, projected from
  Event's events with timezone-aware instants, name and slug, so whole-event
  bookings have an interval and the console can name events without reading
  Event's models.

## Does not own

| Nearby concept | Owned by |
|---|---|
| What was bought, by whom, and under which registration type — including the `resource_claim` and `seating` grants that entitle a booking or a pick | **Registration** (ADR-0005). Resources fulfils grants; it does not grant them. Registration status is never touched by Resources. |
| Prices, orders, invoices, refunds for a booked resource | **Commerce** (ADR-0006). Resources never prices anything and never emits money-shaped events. |
| A person's right to be on site, badge, check-in | **Admission**. A booking is not an access right. |
| The event and the venue as lifecycle entities | **Event**. Resources references `venue_id` and `event_id` as plain ULIDs. |
| Who works when and where | **Staffing**. |
| Game servers and match sessions | **GameOps** (ADR-0012). |
| Matches, brackets, tournament schedules that need a room | **Competition** |
| Public brand pages that embed a seat plan | **Platform** / **Content**. |
| Notifying a holder that a booking expired or a seat was released | **Messaging**. No mail is sent for a plan-conflict release in v1 (operator decision 2026-09-10). |
| Which roles may book on someone else's behalf | **Platform** (per-brand role data, ADR-0013) |
| Raster backgrounds of a plan | **Platform** media library (`MediaPicker`); Resources stores a media id. |

## Public contract (events)

Events are the only way other modules may react to this one (ADR-0004).
Payloads carry ULIDs and scalars — never Eloquent models.

| Event | Emitted when | Payload |
|---|---|---|
| `ResourceBookingHeld` | A provisional hold is placed; nothing irreversible may react to it | `bookingId`, `resourceId`, `brandId`, `eventId`, `resourceTypeId`, `holderType`, `holderId`, `registrationId?`, `claimId?`, `label`, `ref`, `quantity`, `startsAt`, `endsAt`, `holdExpiresAt?` |
| `ResourceBookingConfirmed` | A hold becomes firm — **the event other modules act on** (a badge, a bill) | as above plus `confirmedAt`, without `holdExpiresAt` |
| `ResourceBookingReleased` | A booking gives its interval back | `bookingId`, `resourceId`, `brandId`, `eventId`, `resourceTypeId`, `holderType`, `holderId`, `registrationId?`, `claimId?`, `label`, `ref`, `reason`, `releasedAt`; reasons `registration_cancelled`, `registration_rejected`, `plan_conflict`, `repicked`, `operator_released`, `expired` |
| `PlanSaved` | A plan save committed | `planId`, `eventId`, `brandId`, `version`, `changedPlaceIds` |
| `PlanPublished` / `PlanUnpublished` | A plan reaches or leaves the picker | `planId`, `eventId`, `brandId` |
| `SeatClaimCreated` | A confirmed registration with a `seating` grant may pick | `claimId`, `registrationId`, `eventId`, `brandId` |
| `SeatClaimPlaced` | The registrant's places are confirmed | `claimId`, `registrationId`, `eventId`, `brandId`, `placeIds` |
| `SeatClaimReleased` | The claim ended with its registration | `claimId`, `registrationId`, `eventId`, `brandId` |
| `PlaceSupplyChanged` | A category's bookable supply moves for a reason that is not a sale (design §12b, #282): a place blocked/unblocked, reserved for or released from the organisation, or a whole plan published/unpublished — emitted from `SavePlan`'s projection, `PublishPlan`, `UnpublishPlan`, `ReservePlaceForOperator` and `ReleaseOperatorReservation`, the only places that change these states | `changeId`, `brandId`, `eventId`, `resourceTypeId`, `reason` (`blocked`\|`unblocked`\|`reserved`\|`released`\|`published`\|`unpublished`), `direction` (`consumed`\|`released`), `places` |

Admission's SPEC once referred to `ResourceBookingCreated`; the settled name is
`ResourceBookingConfirmed` — a hold is provisional and must never reach a badge.

### Internal events (observability only)

Not part of the cross-module contract; other modules must not listen to them.

| Event | Emitted when | Payload |
|---|---|---|
| `ResourceBookingRefused` | `AssignPlacesToRegistration` lost the race for a place (23P01 or 40P01) and wrote nothing | `brandId`, `eventId`, `registrationId`, `reason` (`unavailable|deadlock`) |
| `PlanSaveRefused` | `SavePlan` refused before writing: stale version, invalid document, unresolved conflict | `planId`, `eventId`, `brandId`, `outcome` (`stale|invalid|conflict`) |

## Consumes

| Event | Owning module | Listener and behaviour |
|---|---|---|
| `RegistrationConfirmed` | Registration | `FulfilClaimsOnRegistrationConfirmed`, gated per brand: `resource_claim` grants become first-fit bookings, `seating` grants become one `unplaced` claim each with `registrant_user_id` from the payload; idempotent on `(registration_id, amendment_id null)` |
| `RegistrationAmended` | Registration | `FulfilClaimsOnRegistrationAmended`, the same keyed on `amendment_id` |
| `RegistrationCancelled` | Registration | `ReleaseBookingsOnRegistrationCancelled`, **ungated** (see Activation): every active booking of the registration released with `registration_cancelled`, claims → `released` |
| `RegistrationRejected` | Registration | `ReleaseBookingsOnRegistrationRejected`, ungated, reason `registration_rejected` |
| `EventCreated`, `EventRescheduled`, `EventDetailsUpdated` | Event | `ProjectEventWindow`, ungated: upsert of `resource_event_windows` by `(brand_id, event_id)` with timezone-aware instants (ADR-0027), name and slug; `event:replay-details` backfills existing events |
| `DataExportRequested` / `UserDeletionRequested` | Compliance | `ResourcesGdprHandler` via the registry: exports claims, bookings, operator reservations, the plans the subject created and the versions they saved; erasure nulls `registrant_user_id`, `created_by` on plans and versions, and the `holder_id` of the subject's operator reservations |

Resources does **not** listen to Commerce: payment reaches Registration, which
confirms, and Resources reacts to Registration only (ADR-0006). It keeps no
read model of people — a holder is a ULID plus a holder type.

## Surfaces

- **Console** (operator host, `routes/admin/resources.php`): categories
  (`admin/resource-types`), plans (`admin/plans`: index, create, import,
  export, publish, occupancy, operator reservations) and the plan editor
  (`admin/plans/{plan}/edit`, SVG, `SavePlan` through `useHttp` with
  `expectedVersion`; 409 stale, 422 conflicts or invalid). Console code runs
  without a brand: `withoutBrandScope()` at every query level, `brand_id`
  forceFilled from the plan or event window row.
- **Picker** (brand host, `routes/web/resources.php`, module gate):
  `GET|POST /my/registrations/{registration}/seat` and the availability poll.
  Ownership of the claim authorises (`registrant_user_id === auth()->id()`);
  no capability is needed. Only published plans reach the picker.
- **Slots**: `registration.mine.show` → `SeatSummarySlot`;
  `registration.type-editor.seating-fields` → `SeatingGrantFields`;
  `registration.quota-form.categories` (design §12b, #282) → props only, the
  quota console's own category select reads them and renders no foreign
  component; all three carry `module: 'resources'` and no feature.
- **Commands**: `resources:expire-holds` (every minute), `resources:plan-import`,
  `resources:plan-export`, `resources:assign-place`.

## Capabilities

Declared in `src/Enums/Permission.php`; roles that grant them are per-brand
data (ADR-0013), with the module's defaults in
`ResourcesServiceProvider::defaultRoles()` (ADR-0045): `resources.planner` and
`resources.desk`.

- `ViewResources` — view the booking list of an event, including holder ids.
- `ManageResourceTypes` — categories and their booking rules.
- `ManageResources` — create, publish and withdraw plan-less resources.
- `ManagePlans` — plans, the editor, import, export, publish.
- `BookOnBehalf` — pick or reserve for somebody else (operator reservations,
  `resources:assign-place`, the Phase 3 reseat).
- `ReleaseBookings` — release another holder's booking.

Booking one's own places is not a capability: the claim authorises.

## Observability (ADR-0059)

Catalogue in `src/Metrics/ResourcesMetrics.php`, recorders in
`src/Listeners/Metrics/`, gauges in `ResourcesGauges`:
`resource_bookings_total{brand,outcome=held|confirmed|released|expired|refused}`,
`resource_plan_saves_total{brand,outcome=saved|conflict|invalid|stale}`,
`resource_seat_claims_unplaced{brand}`,
`resource_hold_sweep_last_run_timestamp_seconds`. Dashboard row "Seating" on
`konventix-sale`; alert `KonventixResourceHoldSweepStale` (warning, sweep older
than ten minutes; runbook in `docs/operations.md`). The unplaced-claims gauge
is the operator's substitute for a category-versus-quota check Resources cannot
perform.

## Activation

Pennant feature `module:resources`, gated per brand at the four enforcement
points (ADR-0003): the brand-host route group carries `ensure-module:resources`
(added by the base provider), Platform's nav registry filters the module's
entries, the two slots pass `module: 'resources'`, and the fulfilment listeners
short-circuit through `ModuleGate::isActiveForBrand($brandId)` because
`RegistrationConfirmed` may arrive without an ambient brand (a Stripe webhook).
Admin routes carry no module gate; `BrandScope` hardens their queries instead.

Two listener kinds are deliberately **not** gated, recorded in ADR-0060 as the
explicit exception to ADR-0003: `ReleaseBookingsOnRegistrationCancelled` and
`ReleaseBookingsOnRegistrationRejected` release bookings for a brand that has
switched the module off — a release is safe and idempotent, and a brand that
switches Resources off must not keep seats parked for cancelled registrations;
and `ProjectEventWindow` keeps the event-window read model current so a brand
switching Resources on mid-event finds its windows already projected.

With the feature off a brand sells registrations but allocates nothing. A type
that grants claims still registers people correctly — the claims stay
unfulfilled rather than failing the registration, and switching the module on
later fulfils them retroactively through `event:replay-details` and a replayed
confirmation.

## Console: plan editor

`admin/plans/{plan}/edit` renders `PlanEditorData` (the plan as the viewer
sees it, the version the next save must name, the grid step, the event's
categories, the occupancy). The editor saves whole documents through
`PUT admin/plans/{plan}` as JSON `PlanSaveData { expectedVersion, document,
resolutions }` and gets one of three answers: `200 PlanSaveResultData`,
`409 { currentVersion }` when the version moved, `422 { conflicts,
errors: { conflicts } }` when an occupied place would be deleted,
recategorised or blocked without a `release` resolution (the list is
mirrored under `errors` because Inertia's `useHttp` hands a 422's `errors`
key to the page and nothing else). `POST admin/plans/{plan}/validate` is
the same pipeline without the write and answers `PlanValidationData`.
Geometry is edited only in the document; occupancy is read from the
projection and never from the document. Operator reservations go through
the `reserve`/`unreserve` actions behind `BookOnBehalf`.

## Settled questions

- Capacity greater than one: per type, `unit` or `quantity` mode (ADR-0034).
- No-overlap enforcement: a PostgreSQL exclusion constraint over a range
  type (ADR-0020); the suite runs on PostgreSQL.
- Waitlists belong to Registration (D-REG-04); a waitlist conversion is an
  ordinary confirmation followed by a pick.
- Interval granularity: minute-precision half-open ranges; the whole event
  is the degenerate case (D-BATCH-7).
- Transfers follow the registration's amendment rules (ADR-0036); a seat
  belongs to the registration, Umschreibung is not modelled (#16).
- Resources exist per event only (D-EVT-13); floors and templates are
  deferred.
- Where a seat plan lives: in this module as a document behind a typed cast,
  projected into `resources`; element id equals resource id (ADR-0060).
- When a registrant picks: after confirmation, never in the registration
  form and never with a hold at submit (operator decision 2026-09-10).
