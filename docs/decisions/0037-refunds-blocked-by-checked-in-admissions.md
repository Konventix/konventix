# ADR-0037: Refunds are blocked while an admission of the registration is checked in

**Status:** accepted

## Context

Commerce's last open SPEC question: what does a refund mean when the
registration's admissions have already been checked in? The recommended
option kept Commerce opinion-free (report the refund, let Registration
decide). The user decided otherwise in the Phase 2 Round 7 interview:
**a checked-in admission blocks the refund** — money does not come back
while the person is (or has been) inside.

The architectural tension: Commerce may not read Admission's tables
(ADR-0004 — no cross-module Eloquent imports), yet the block requires
Commerce to answer "is anything on this registration checked in?" at
refund time.

## Decision

**Commerce refuses to execute a refund for an order whose settled
registration has at least one checked-in admission. It answers that
question from its own check-in read model, populated by listening to
Admission's check-in events — never by querying Admission's tables.**

- Admission's `AdmissionCheckedIn` / check-out events must carry
  `registration_id` (and `brand_id`) in their scalar payload, so Commerce
  can maintain `commerce_checked_in_registrations` (or equivalent) keyed by
  registration ULID without joining foreign tables.
- The block is a *guard in the refund Action*, surfaced to the operator as
  a refusal with reason — not a silent failure. An organizer who genuinely
  wants to refund a checked-in attendee first revokes/checks out the
  admission (an Admission-side action they already have), then refunds.
- Cancellation of the registration itself is not blocked — only the money
  flow is. The organizer can still cancel and handle goodwill manually.

### Alternatives considered

**Commerce reports, Registration decides (recommended in the interview).**
Keeps Commerce free of admission knowledge entirely. Rejected by user
decision: the operator wants the money rail itself to enforce the rule,
not depend on organizer discipline downstream.

**Commerce queries Admission directly.** Simplest to write, forbidden by
ADR-0004 — and the first such import would normalize boundary violations.

## Consequences

- The read model is release scope for the refund flow: refunds cannot ship
  before Admission's check-in events (with `registration_id` in the
  payload) exist. Build order: Admission check-in chain first, then
  refunds.
- A replayed or out-of-order check-in event stream must converge: the read
  model stores current state per admission ULID, not a counter.
- Offline check-ins reconcile late (ADR-0033). A refund executed in the
  window before a device syncs its scans is accepted risk — the guard is
  best-effort against the data Commerce has at refund time.
- If a future partial-refund flow refunds *unused* admissions of a
  partially checked-in group, the guard needs per-admission granularity;
  the read model's per-admission keying already permits that.
