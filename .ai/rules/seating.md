---
paths:
  - 'app-modules/resources/**'
---

# Seating (Resources)

## Booking writes go through the three Actions; the exclusion constraint is the invariant
`HoldResourceBooking`, `ConfirmResourceBooking`, `ReleaseResourceBooking` — never an insert or update on `resource_bookings` from anywhere else, including the plan save and the sweep. The partial exclusion constraint `resource_bookings_no_overlap` decides every race (ADR-0020); an application check would lose one it cannot see. Catch SQLSTATE `23P01` (`ResourceUnavailableException`) and `40P01` (deadlock) inside the Action and translate them into a domain or validation exception with the place ref in the text. A registrant's seat booking is held and confirmed in one transaction and carries no clock; only direct holds have `hold_expires_at` and only those the sweep touches (ADR-0060).

## `resource_bookings.admission_id` is written by `HoldResourceBooking` only, and one place per admission is the schema's rule
`AssignPlaceToAdmission` (#292 §6.3) binds a place to a ticket by passing `admissionId` into the hold; nothing else writes the column. The partial unique index `resource_bookings_one_place_per_admission` (occupying rows with a non-null `admission_id`) refuses a second place for the same ticket at the database — catch it like `23P01` and name the place. The claim's `quantity` still caps the group *across* bound and unbound bookings; picking a place the group already holds unbound rebinds it rather than refusing, which is also how bookings from before the column migrate onto tickets. The registration-level pick path (`AssignPlacesToRegistration`, `SeatPickController`) is not dead: it serves the console reseat, the CLI, and a claim whose registration has no admissions in `resource_admissions`.

## `resource_bookings.holder_id` is nullable; only an operator's can actually be null
Erasure (`ResourcesGdprHandler`) nulls an operator reservation's `holder_id`, and the row can stay `confirmed` indefinitely with no clock — so a release built from that row long after erasure must tolerate a null holder. `ResourceBookingReleased`/`ResourceBookingConfirmed`'s `$holderId` are `?string` for exactly this reason (`ResourceBookingHeld`'s is not: it is always built from a fresh caller argument, never a re-read row). A registration-held booking's `holder_id` can never be null — it is the registration id, not a person's — and `resource_bookings_registration_holder_not_null` enforces that at the schema level; do not add application code that relies on it instead.

## Geometry lives in the document, availability in the projection
A plan's zones, groups, places, fixtures and annotations exist only in `resource_plans.document` (`PlanDocumentData`, `konventix.plan/v1`, integer millimetres, camelCase keys). `SavePlan` projects places into `resources` (element id equals resource id, `ref` unique per plan, derived `zone_id`/`group_id`/`neighbors`/`is_published`). Booking logic, the picker's availability and the console's occupancy read only `resources` and `resource_bookings` — never parse the document for availability, never write geometry into the projection by hand.

## Console code runs without a brand
Admin-host requests resolve no brand and `BrandScope` hardens every brand-owned query to zero rows: `withoutBrandScope()` at every query level, including relations and nested `whereHas`, and `forceFill(['brand_id' => …])` from the plan or event-window row on every create. `Gate::authorize(Permission::X->value)` is the first line of every controller method.

## Listeners resolve scoped bindings inside handle(); the release listeners are ungated on purpose
Every listener is registered with `Event::listen` in `ResourcesServiceProvider` (discovery is off) and reads `ModuleGate`/`BrandContext` at call time. Fulfilment listeners gate with `ModuleGate::isActiveForBrand($brandId)` because a confirmation may arrive without an ambient brand (webhook, scheduler). `ReleaseBookingsOnRegistrationCancelled`, `ReleaseBookingsOnRegistrationRejected` and `ReleaseBookingOnAdmissionRevoked` are **not** gated (ADR-0060's ADR-0003 exception): a release is safe and idempotent, and a brand that switched the module off must not keep seats parked. Do not "fix" that by adding the gate. `AdmissionHolderUnassigned` deliberately has no release listener: the seat is the ticket's, the next holder inherits it (#292 §6.5).

## Registration status is never touched by Resources; grants are frozen once a type has registrations
A plan conflict releases the booking (`plan_conflict`) and sets the claim back to `unplaced`; it never cancels, rejects or amends the registration. Seating grants (`GrantKind::Seating`, `resourceTypeIds`, `seatingMode`) are configured when the type is created; `GuardsTypeConfiguration` freezes `grants` afterwards. A seat category's ceiling is a hand-typed Registration quota (#282), never a Resources concept — the operator sizes it against `Resource::scopeBookable()`'s count (published, not blocked), so a blocked or draft place does not consume a unit of it. `resource_seat_claims_unplaced` is the closest Resources gets to policing that ceiling: a gauge, not an enforced check.

## Tests
`Event::fake([...])` always with explicit classes (a blanket fake swallows `BelongsToBrand`'s creating hook). Shared helpers live in `app-modules/resources/tests/Helpers.php`, prefixed `seating`. An assertion after a constraint violation needs the failure inside a savepoint — the Actions' nested `DB::transaction` provides one; a raw insert does not. Admin-host tests create their rows before the request. `vendor/bin/sail npm run build` before a test that renders a new Inertia page. Never two test processes at once.

## Observability
Every operational state here has a metric, a panel and, where it can fail silently, an alert (ADR-0059): catalogue in `src/Metrics/ResourcesMetrics.php`, recorders in `src/Listeners/Metrics/` on this module's own events (`ResourceBookingRefused` and `PlanSaveRefused` exist only for that), gauges in `ResourcesGauges`, the Seating row in `contrib/observability/src/sale.mjs`. A new metric without a panel fails `ObservabilityCoverageTest`.
