# ADR-0007: Each organizer connects their own PSP account

**Status:** accepted; its Stripe mechanism is rewritten by
[ADR-0058](0058-one-stripe-account-per-instance.md) (2026-09-08). The ruling
below stands — the platform never holds funds and never sits in the flow of
funds — but "connect your own account through Stripe Connect" becomes "the
operator running this instance **is** the merchant of record, on its own
Stripe account, reached by a secret key". Several sellers means several
Konventix instances (ADR-0001), not several connected accounts under a
platform. Mollie and Unzer are unaffected.

## Context

If the platform receives participants' money on behalf of organizers, it acts
as a payment service and triggers BaFin licensing (ZAG). This is fatal for a
small team.

## Decision

Money flows directly from participant to organizer: each organizer/operator
connects their **own** Stripe (Connect Standard) or Mollie account. The
platform never holds funds and never sits in the flow of funds.

## Consequences

- Onboarding includes PSP account connection per operator/brand — for
  Stripe since ADR-0058 that connection is the operator's own key, entered
  once per instance, and a brand's Stripe row is only the flag that says
  this brand sells through it.
- Platform monetization (SaaS fees) is billed separately, never deducted from
  ticket revenue.
- Settlement adapters (ADR-0006) must be built account-scoped from day one.
