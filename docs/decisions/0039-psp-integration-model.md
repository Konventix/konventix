# ADR-0039: PSP integration — hosted checkout, webhook-confirmed settlement

**Status:** accepted

## Context

ADR-0007 settled *whose* account money flows into (each organizer's own —
the platform never holds funds) and Slice 7a built the seam
(`SettlementAdapterRegistry`, `SettlementAccount`). What remained open was
*how* a PSP integration works mechanically: where card data is entered, what
event marks an order paid, where per-brand API credentials live, and how a
webhook proves it came from the provider.

Release targets three providers: **Stripe** (release-critical), **Mollie**,
and **Unzer** as the German alternative. Each has different connection and
webhook-verification models, and the answers must not be invented three
times.

## Decision

1. **Hosted checkout only.** The participant is redirected to the PSP's own
   payment page (Stripe Checkout Session, Mollie hosted payment, Unzer
   Paypage). No card fields ever render on Konventix pages — the platform
   stays out of PCI scope (SAQ A) the same way ADR-0007 keeps it out of the
   flow of funds. Adapters that can do this implement
   `ProvidesHostedCheckout` alongside `SettlementAdapter`; `manual` and
   `null` deliberately do not.

2. **Webhooks are the only source of settlement truth.** The redirect back
   to the platform proves nothing — a payer can pay and never return, or
   return before an asynchronous method (SEPA, bank transfer) settles.
   `OrderPaid` is emitted exclusively from a verified webhook, never from
   the return page, which only *displays* current state. Verification is
   per-provider, using each provider's own model:
   - **Stripe**: signature verification of the raw payload
     (`Stripe-Signature`, HMAC-SHA256, platform signing secret).
   - **Mollie / Unzer**: the webhook body is treated as an untrusted hint
     carrying only an identifier; the truth is **fetched back** from the
     provider's API with the brand's own credentials. A forged call can at
     worst make us re-read real state.

3. **`Payment` is a first-class record.** Every checkout attempt creates a
   row (`pending` → `succeeded` / `failed` / `expired`), keyed uniquely per
   `(provider, external_id)` so webhook replays converge instead of
   double-settling. `PaymentReceived` / `PaymentFailed` move from proposed
   to implemented. The order remains the authoritative money record
   (ADR-0006); payments are the attempts that settle it.

4. **Credentials custody.** Per-brand PSP credentials (Mollie API key,
   Unzer private key) live encrypted on `SettlementAccount.credentials` —
   never in `.env`, never logged. Platform-level credentials (the Stripe
   platform secret and webhook signing secret) live in `config/services.php`
   from environment, because they belong to the platform, not to a brand.
   Stripe stores no per-brand secret at all: the connected account id plus
   the platform key is sufficient (`Stripe-Account` header).

5. **Stripe is the SaaS/direct-charge pattern.** The organizer is merchant
   of record on their own account: charges are created *on* the connected
   account, the connected account pays Stripe's fees, Stripe carries the
   loss liability, and the organizer keeps the full Stripe dashboard. No
   `application_fee_amount`, ever — ADR-0007 forbids deducting platform
   monetization from ticket revenue. Connected accounts are created through
   the v2 Accounts API (the successor of "Connect Standard"); legacy
   `type:` account creation is not used.

## Consequences

- A checkout page never needs a PSP SDK on the client: it links out.
- A paid order can exist without anyone ever seeing the success page; every
  post-payment effect (Registration confirmation via `OrderPaid`) hangs off
  the webhook path and is covered by tests there.
- Webhook endpoints live in `routes/api/` (stateless, no CSRF, no session
  — the third route surface, established by ADR-0033) and are idempotent
  per `(provider, external_id)`.
- Refunds gain their PSP side: `SettlementAdapter::refund()` is part of the
  contract, a no-op for `manual` (the organizer wires the money back) and a
  real API call for PSP adapters, executed inside `RefundOrder` after the
  ADR-0037 check-in guard passes.
- A fourth provider is: one adapter class, one `register()` line, one
  webhook route, and rows in no new tables.
- ADR-0057 extends the webhook's authority to the involuntary reversal — a
  chargeback, a Rücklastschrift, a PayPal dispute — the same
  settlement-truth-from-webhook rule this ADR establishes, applied to money
  leaving after `OrderPaid` instead of arriving.
