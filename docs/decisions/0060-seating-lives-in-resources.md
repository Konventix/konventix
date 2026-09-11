# ADR-0060: Seating lives in Resources — a place is a resource, a plan is a document

**Status:** accepted (2026-09-10, operator decisions in the question round of
the same day; design at docs/superpowers/specs/2026-09-10-seating-design.md;
GitLab #80 is the umbrella, #28, #52, #73, #70, #104, #105 the parts)

## Context

The SXLan cutover (sales open 21.09, C16 on 22.09 expects "Sitzplatz belegt")
needs a hall plan an operator can draw, publish and let confirmed
registrations pick a place on, with the guarantee that two people never hold
the same place. `SeatingSPEC.md` v1 (2026-09-09) described that feature as a
new context under `app/Contexts/Seating` with its own `places` table,
`place_slots`, a state column per place, floors, templates and a Redis
document cache.

None of that fits what exists. The codebase is an InterNACHI modular monolith
under `app-modules/`, `docs/architecture/module-map.md` assigns Seating to
Resources ("a seat is one resource type with a time dimension"), and the
Resources module already has the entities a seat needs: `resource_types`
(event-scoped, slug-named, what a registration's grants reference),
`resources` (one row per bookable unit in unit mode, ADR-0034) and
`resource_bookings` with the partial exclusion constraint
`resource_bookings_no_overlap` that ADR-0020 makes the single no-overlap
invariant. A second booking system beside it — a `state` on a places row, a
slot grid — would be exactly the application-level copy of the invariant
ADR-0020 rejected, and a `seating` module could not write `resource_bookings`
without importing Resources' models, which ADR-0004 forbids.

Two inventory questions were still open in the decision log. D-EVT-06 ("who
picks the concrete resource, and when?") and D-EVT-07 ("how does 'this block
is only for VIP tickets' survive ADR-0004?"). Both are closed here. D-EVT-13
(the resource catalogue is per event, no venue-level catalogue) is not
touched: plans are per event, and floors and templates stay out of scope.

The Resources SPEC also says "an unconfirmed booking is a temporary hold with
a deadline, released automatically when it passes". A seat picked from a
phone must not be parked behind a clock: the pick is confirmed in the same
transaction as the hold, or it fails. That sentence needed narrowing.

## Decision

1. **Seating is a surface of the Resources module, not a module of its
   own.** Namespace `Konventix\Resources`, flat module directories
   (`src/Actions`, `src/Data`, `src/Models`, `src/Plans`, `src/Http`,
   `resources/js/pages`, `resources/js/components/plan`), routes, nav, slots,
   schedule, commands, GDPR handler and metrics all registered by
   `ResourcesServiceProvider`. The module map's Resources row gains one
   sentence; no new module, no new dependency.

2. **A place is a unit-mode `Resource`.** The `resources` table is the
   projection of the plan: it gains `plan_id`, `ref`, `zone_id`, `group_id`,
   `accessible`, `blocked`, `neighbors`, `origin_id` and `deleted_at`
   (`SoftDeletes`, so a removed place keeps its booking history). The
   element id in the document **is** the resource id. `is_published` of a
   plan-placed resource is derived from `resource_plans.published_at` on
   every save; plan-less resources (booths, devices) keep the manual flag.

3. **A category ("Kategorie") is a `ResourceType`.** It gains
   `description`, `token` (a semantic design token name — `chart-1`…`chart-5`,
   `accent`, `success`, `warning`, `danger` — never a hex value, ADR-0008),
   `symbol` (a lucide icon key, mandatory in the picker so state is never
   conveyed by colour alone, ADR-0055), `sort_order` and `default_shape`. A
   seating grant references categories exactly the way a `resource_claim`
   grant references its `resourceTypeId`: by ULID, no FK, no read model in
   Registration.

4. **The plan is one JSON document behind a typed cast, projected into
   `resources`.** `resource_plans.document` holds a `PlanDocumentData`
   (`konventix.plan/v1`: zones, groups, places, fixtures, annotations in
   integer millimetres) cast by laravel-data's Eloquent cast (ADR-0009: a
   JSON column only behind a typed cast; the TypeScript type is generated).
   `resource_plan_versions` keeps one row per save, pruned to the last 50.
   Geometry lives only in the document; booking logic reads only the
   projection; availability is a query over `resource_bookings`. Nothing
   ever queries the document for availability. Saves are optimistic: the
   request carries `expectedVersion`, a mismatch is a 409, an occupied place
   that would be deleted, recategorised or blocked is a conflict the save
   refuses until the operator resolves it.

5. **A registrant picks after confirmation, never in the registration
   form.** Free types pick immediately, paid types after payment, approval
   types after approval. There is no hold at submit and no coupling to the
   cart clock; a seat is never parked for an unpaid registration. A seat
   belongs to the registration; N places for quantity N are one set.

6. **Two kinds of hold.** A *clocked direct hold* (`HoldResourceBooking`
   without an immediate confirm — booths, devices, the operator desk) expires
   through `hold_minutes` and the `resources:expire-holds` sweep. A *seat
   booking* is held and confirmed inside one transaction of
   `AssignPlacesToRegistration`; the exclusion constraint decides the race,
   and the sweep never sees it. This narrows the Resources SPEC's "every
   unconfirmed booking is a hold with a deadline" to the first kind. The SPEC
   is amended in the same commit.

7. **The release listeners on `RegistrationCancelled` and
   `RegistrationRejected` are not module-gated.** ADR-0003 gates listeners
   per brand; `RegistrationCancelled` carries no brand id, and a brand that
   switched Resources off after selling seats must still get its places
   back. Releasing a booking is safe and idempotent, so these two listeners
   run regardless of the gate. This is recorded here as the explicit
   ADR-0003 exception, beside the ungated `ProjectEventWindow` read model.
   Every listener that *creates* bookings or claims stays gated, through
   `ModuleGate::isActiveForBrand()` where the event fires without an ambient
   brand.

8. **D-EVT-06 and D-EVT-07 are settled; D-EVT-13 stands.** D-EVT-06: an
   unallocated entitlement is its own row, `resource_claims` (event,
   registration, registrant user, allowed resource type ids, seating mode,
   quantity, state `unplaced|placed|released`) — never a fake booking.
   D-EVT-07: the eligibility rule is a list of resource type ULIDs on the
   grant, snapshotted onto the claim, so Resources decides eligibility from
   its own row and Registration never learns what a plan is. D-EVT-13:
   plans are per event; floors, templates and plan copy across events are
   the "Nach Event" issue.

### Alternatives considered

**A `seating` module beside Resources.** Cleaner on paper, and the v1 spec's
shape. Rejected: it cannot write `resource_bookings` without importing
Resources' models (ADR-0004), so it would need a second booking table with a
second no-overlap invariant — the design ADR-0020 rejected — or an event
handshake for every hold, confirm and release, which is not atomic.

**Normalised `zones`, `groups`, `places` tables.** Rejected: the editor
loads and saves whole documents, nothing queries a zone on its own, and the
projection already hands booking logic what it needs. Three tables would be
three write paths for one save.

**A separate `places` table with the geometry columns.** Rejected: it
duplicates `resources` row for row and reintroduces the question of which
of the two is bookable.

**Picking in the registration form, with a hold at submit.** Rejected by
the operator (2026-09-10): a seat parked for an unpaid registration blocks
paying registrants, couples seating to the cart clock, and needs a re-pick
flow for every expired hold. Picking after confirmation removes all three.

## Consequences

- `app-modules/resources` grows console pages, a brand-host picker, an
  editor and four artisan commands; the module map's one-line description
  of Resources now names seating explicitly.
- `resources.deleted_at` means every reader of the table must decide
  whether it wants deleted places (booking history does, the picker does
  not); `SoftDeletes` hides them by default and `withTrashed()` is the
  greppable opt-in.
- The document schema is additive: `ElementKind` and `ShapeKind` are enums,
  a new kind is a new case plus its `PlanElementData` fields. Removing a
  field is a version bump of `konventix.plan/v1`.
- `GuardsTypeConfiguration` still freezes `grants` once a type has
  registrations, so a seating grant must be on the SXLan types before the
  Eventula import and before sales open (recorded in #80).
- The two ungated release listeners are the only Resources code that runs
  for a brand with the module off; anything else added to that list needs
  this ADR amended.
- The Resources SPEC's "Hold expiry" bullet is narrowed to clocked direct
  holds; the SPEC is updated in the S1 commit that adds the schema.
