# ADR-0036: Amendments are delta orders; the billing party freezes at invoicing

**Status:** accepted

## Context

Round 6 (D-BATCH) deferred two questions "until the paid-flow build":
amendment (adding admissions or resource claims to an already-confirmed
registration) and transfer (changing a registration's invoice recipient
after confirmation). The paid flow is now built, so both are due. The
constraint shaping both is ADR-0031: an issued invoice is immutable —
whatever amendment and transfer mean, "edit the invoice" is not available.

The exhibitor case makes amendment real: "Musterfirma adds two crew badges
three weeks after their booth invoice went out" is normal business, and
cancel-and-recreate would void a paid invoice to accommodate it.

## Decision

**An amendment is a delta: it creates a new order (and, for invoice-settled
types, a new invoice) for the added grants against the same registration.
The original invoice is never touched. The billing party is editable until
the first invoice is issued and frozen from then on; changing it afterwards
is a deliberate, capability-gated flow that credit-notes and re-issues.**

- Amendment adds grants only. Changing a registration's *type* remains
  cancel-and-recreate — a type change re-opens every axis (settlement,
  approval, gate) and is not a delta.
- The amendment confirms through its own event (payload: registration ULID,
  the delta grants, its own order reference), so Admission can issue exactly
  the added admissions idempotently without re-processing the original
  grant set.
- One registration therefore maps to N orders over its lifetime. Commerce's
  `settles_type`/`settles_id` already supports this; "the order of a
  registration" was never promised to be singular.
- Transfer before invoicing is a plain edit. Transfer after invoicing
  issues a credit note for the open invoice and a new invoice addressed to
  the new party, drawing a fresh number — the paper trail shows both
  documents, which is what GoBD wants anyway.

### Alternatives considered

**Cancel & recreate only.** Least construction, but voiding a paid booth
invoice to add two badges punishes exactly the customers who pay the most.
Rejected.

**Full re-issue (credit-note the original, one consolidated new invoice).**
Cleanest single-document story, but makes credit notes a prerequisite for
the *common* amendment case and reissues paid invoices, which confuses
bookkeeping on the buyer's side too. Rejected as the default; it remains
the mechanism for post-invoice *transfer*, where re-addressing is the
whole point.

## Consequences

- Credit notes stop being a refund-only concept: post-invoice transfer
  needs them. They enter release scope with the refund flow.
- Registration's waitlist promotion and amendment share machinery: both
  produce "additional grants materialize later" events with their own
  settlement request.
- Reporting "what did this exhibitor pay" is now a sum over orders, not a
  single row — the orders admin index groups by `settles_id`.
- Resources' open transfer question inherits the same shape: a
  resource-booking transfer follows the registration's amendment/transfer
  rules, not its own.
