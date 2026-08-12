# ADR-0007: Each organizer connects their own PSP account

**Status:** accepted

## Context

If the platform receives participants' money on behalf of organizers, it acts
as a payment service and triggers BaFin licensing (ZAG). This is fatal for a
small team.

## Decision

Money flows directly from participant to organizer: each organizer/operator
connects their **own** Stripe (Connect Standard) or Mollie account. The
platform never holds funds and never sits in the flow of funds.

## Consequences

- Onboarding includes PSP account connection per operator/brand.
- Platform monetization (SaaS fees) is billed separately, never deducted from
  ticket revenue.
- Settlement adapters (ADR-0006) must be built account-scoped from day one.
