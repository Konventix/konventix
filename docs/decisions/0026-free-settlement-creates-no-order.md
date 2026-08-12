# ADR-0026: `settlement: free` creates no Commerce record

**Status:** accepted

## Context

Commerce's SPEC left one question explicitly open for Phase 1: does a
registration whose type declares `settlement: free` produce a zero-total order
for reporting symmetry, or no Commerce record at all? Phase 1 runs free
settlement only, so this is the cheapest moment to answer it — the answer
decides whether every registration has an order.

## Decision

**A free registration never touches Commerce.** No order, no order lines, no
events. Commerce participates exactly when a registration type declares a
settlement that moves money (`instant`, `invoice`, `external`), which is when
Registration emits `RegistrationSettlementRequested`.

- ADR-0006 stays clean: the order is the source of truth *for order state*, and
  order state only exists where there is something to settle.
- Reporting on free registrations (counts, quotas, conversion) is
  Registration's job — it owns the registration lifecycle either way. A
  zero-total order would duplicate that state for symmetry and then have to be
  excluded from every revenue view.
- Commerce stays genuinely dormant in a free-only brand, which is also the
  Phase 1 PoC boundary proof: the flow works end-to-end with the module never
  waking up.

## Consequences

- "Every registration has an order" is false by design; anything that wants
  per-registration financial state must check the settlement axis first.
- If a free registration is later amended into a paid one (open amendment
  question in Registration's SPEC), the order is created at amendment time via
  the same `RegistrationSettlementRequested` path — not retrofitted.
- ~~The paired "where does the price live" question remains open.~~
  *Correction: ADR-0017 had already settled it (declaration on the type,
  calculation in Commerce); this consequence repeated the SPECs' stale claim.*
