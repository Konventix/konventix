# ADR-0006: Own Order/Invoice entities; PSPs are settlement adapters

**Status:** accepted

## Context

LanCore leaned on Cashier/Stripe as the source of order truth. Invoice-based
settlement (exhibitors, B2B) has no Stripe checkout session to hang state on.
The association's bookkeeping and tax advisor need proper invoices.

## Decision

- Commerce owns its own `Order` and `Invoice` entities as the source of truth.
  Stripe/Mollie/PayPal/bank transfer are **settlement adapters** behind one
  contract.
- Per-brand invoice number ranges (legally clean when brands appear as separate
  identities).
- Manual payment booking is first-class; optional bank reconciliation later.
- Payment terms, dunning levels, cancellation, and partial credit notes live in
  the domain model.
- Net vs. gross price display hangs on the RegistrationType (B2B net, B2C
  gross), not globally.
- Commerce is loosely coupled to registration: it emits `OrderPaid` (and
  related) events; Registration consumes them to confirm registrations and
  create admissions. Commerce never touches Registration models.

## Consequences

- More own modeling up front; Stripe Invoicing may assist but never leads.
- Refund/credit flows are domain flows, with PSP calls as side effects.
