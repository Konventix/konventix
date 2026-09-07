# ADR-0046: A brand offers the payer every live settlement account — one per provider, one per order

**Status:** accepted

## Context

Slice 7 ruled that "which account settles a brand's money is always a
single answer": a partial unique index allowed one live `SettlementAccount`
per brand, switching providers was disconnect-then-connect, and
`SettlementAccount::liveFor()`, the registry's `forBrand()` and the checkout
page all assumed it. The request that reverses it is ordinary: a brand
connects Stripe for cards and Mollie for PayPal and iDEAL, and the person
paying should see both. ADR-0007 is untouched — every account is still the
brand's own and the platform never sits in the flow of funds.

## Decision

**A brand may have one live account per provider, and the payer chooses
which account settles an order.**

- The unique index becomes `(brand_id, provider) WHERE disconnected_at IS
  NULL`. `liveFor()` is gone; `liveAccountsFor()` is the only way to ask.
- What is offered is a **button per live account**, labelled by an
  operator-editable `checkout_label` or the adapter's default. The PSP's
  hosted page chooses the actual method (ADR-0039 stays: no card fields, no
  method vocabulary on our side).
- An account is *offered* only when it is live and connected. Until
  ADR-0058 a Stripe account additionally had to report an active
  `card_payments` capability, refreshed on return from onboarding and
  daily; with Connect gone a Stripe row **is** the enablement flag — "this
  brand sells through this instance's Stripe account" — and it is offered
  when the instance has a Stripe secret key configured at all. It carries
  no account of its own to pick between; what distinguishes one brand's
  Stripe payments from another's is metadata on the session and the intent,
  plus the brand's own `checkout_label` and `statement_descriptor_suffix`.
- **One account per order**: the `Payment` row names the account; once a
  payment has succeeded no other account can be started against that order.
  Refunds go back through the account that took the payment, which is what
  `RefundOrder` already does.
- `settlement_method` on the settlement events is the provider, always; the
  events also carry the account id.

### Alternatives rejected

**Explicit method rows per account.** Precise, but Stripe's dynamic payment
methods are configured in the organizer's dashboard, not by us; a method
vocabulary we own would drift from what the PSP shows.

**Discovering methods from the PSPs.** Three fetch seams and a refresh sweep
for a list the hosted page renders anyway.

## Consequences

- The settlement-account console is a list per brand; "connect another
  provider" is the normal case.
- The bookkeeping export names the provider per document (metadata; ADR-0040
  still books documents only).
- Tests that asserted "only one live account" now assert "only one per
  provider".
