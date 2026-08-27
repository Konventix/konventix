# Commerce — Specification

> Stable document. Describes what this module *is*. Task tracking lives in
> `TODO.md`.

## Purpose

Commerce owns **money**: what was ordered, what it costs, what was invoiced,
what was paid, and what still is not. Its `Order` and `Invoice` entities are
the **source of truth**, not a mirror of a payment provider's state (ADR-0006).
Stripe, Mollie, PayPal, and bank transfer are **settlement adapters behind one
contract** — replaceable, non-authoritative, and never the place where order
truth lives. Invoice-settled exhibitor business has no checkout session to hang
state on, and the association's bookkeeping needs proper invoices; both
requirements point at the same answer, which is that the domain model leads and
the provider follows.

The platform **never holds funds** (ADR-0007). Money flows directly from the
participant to the organizer through the organizer's own connected PSP account.
Anything else would make Konventix a payment service and trigger BaFin/ZAG
licensing.

## Owns

- **`Product` and `ProductVariant`** — the shop's catalogue (ADR-0047,
  roadmap slice 24). A `Product` belongs to a brand and optionally names one
  event by plain ULID (ADR-0014, never a foreign key — Commerce never reads
  Event's tables for it); `unit_amount_net`, `tax_class` and `price_display`
  are its defaults. A `ProductVariant` is the purchasable line — a size, a
  colour, or, for a product without variation, the single implicit row a
  later slice creates so every product has exactly one stock counter.
  **Stock is a quota in the Registration sense**: `stock_capacity` and
  `stock_consumed` are counters only, copied from `Quota`'s contract rather
  than imported (ADR-0004) — `consumeStock()`/`releaseStock()` never lock or
  open a transaction themselves, callers do that under their own Action's
  `DB::transaction`. `sku` is unique per brand only when set, so many
  implicit variants with no sku never collide.
- **`Cart`** — the pre-order assembly of what someone intends to buy.
- **`Order` and order lines** — the authoritative record of what was ordered,
  at what price, with which tax treatment, in which currency. Orders reference
  what they settle (a registration, a resource booking, a shop item) by ULID.
  **Settled (ADR-0026): a `settlement: free` registration produces no
  `Order` at all** — no row, no order lines, no events. Commerce participates
  exactly when a registration type declares a settlement that moves money
  (`instant`, `invoice`, `external`), which is when Registration emits
  `RegistrationSettlementRequested`. "Every registration has an order" is
  false by design; reporting on free registrations (counts, quotas,
  conversion) is Registration's job, not a zero-total order kept in sync here
  for symmetry.

  **"One order per registration" is equally false (ADR-0036).** A registration
  maps to N orders over its lifetime — the initial settlement plus one per
  priced amendment — so idempotency is keyed on `request_key` (the ULID of
  whatever requested settlement), unique per `settles_type, settles_id,
  request_key`, not on `settles_id` alone. A replayed request still finds its
  existing order; a genuinely new request against the same registration gets
  its own. "What did this exhibitor pay" is therefore a sum over orders, and
  the orders index groups by `settles_id`.
- **Pricing and tax calculation** — line totals, tax rates, and the net-vs-gross
  *display* decision, which Commerce applies from the flag declared on the
  `RegistrationType` (ADR-0006).
- **`Invoice`, credit notes, and cancellations** — including **per-brand
  invoice number ranges**, which are legally cleaner when brands appear as
  separate identities (ADR-0001).
- **Payment terms, due dates, and dunning levels** as domain concepts, not as
  provider callbacks.
- **Payment records**, including **first-class manual payment booking** — an
  organizer marking a bank transfer as received is a normal, supported flow,
  not a workaround.
- **Refunds and partial credit notes** as domain flows, with PSP calls as side
  effects.
- **Settlement adapters** behind a single contract, **account-scoped from day
  one** (ADR-0007): each brand or operator connects its own Stripe, Mollie or
  Unzer account, and every adapter call carries that account. ADR-0039 pins
  the mechanics: hosted checkout only, settlement truth exclusively from
  verified webhooks, per-brand credentials encrypted on the account row.
- **`SettlementAccount`** — one live account per provider per brand
  (ADR-0046): a brand may connect Stripe for cards and Mollie for PayPal and
  iDEAL at once, and the checkout page offers every account that is live,
  connected, and — for Stripe — has an active `card_payments` capability.
  The payer chooses which account settles their order; `settlement_method`
  on the settlement events is always the provider, and the events also carry
  the account id.
- **Vouchers and discount codes**, their rules and their redemption records.
- **Bookkeeping exports** for the tax advisor (ADR-0040): a DATEV
  Buchungsstapel plus a readable journal, one export per operator and month,
  all of the operator's brands in one file (D-COM-05), invoices and credit
  notes only — payments are booked by the advisor from bank and PSP
  statements. Account mapping (`BookkeepingSettings`) is per operator with
  SKR03 Automatikkonten defaults. Each document row names the settlement
  provider (ADR-0046: the latest succeeded payment on its order) as
  metadata — a `Zahlungsanbieter` column in the readable journal, the
  `Beleginfo – Art 1`/`Inhalt 1` pair in the DATEV batch; the export still
  books documents only.

## Does not own

| Nearby concept | Owned by |
|---|---|
| Registration status, confirmation, approval, quotas | **Registration**. Commerce **never touches Registration models** — it emits `OrderPaid` and Registration decides what that means for its own status (ADR-0006) |
| The rule that payment confirms a registration | **Registration** — `confirmation_gate` decides that, and for `on_approval` or `on_submit` types payment is irrelevant to confirmation (ADR-0005) |
| Admissions, badges, ticket tokens, check-in | **Admission** — paying grants no access; a confirmed registration does |
| The net-vs-gross flag itself, and the price declaration | **Registration** — it hangs on the `RegistrationType`, never on a global Commerce setting. Commerce applies it |
| Custody of participants' money | **Nobody here.** The platform never sits in the flow of funds; the organizer's own PSP account receives it (ADR-0007) |
| Platform SaaS fees and their billing | **Out of scope for this module** — platform monetization is billed separately and is never deducted from ticket revenue (ADR-0007) |
| The customer's account, profile, and address of record | **Identity** — the payer is recorded on every order as a user ULID by the module that requests settlement (ADR-0044); Commerce keeps no profile read model |
| The brand itself, its domains, and its onboarding UI shell | **Platform** — Commerce owns the settlement account behind that onboarding step |
| Sending invoices, payment reminders, and receipts | **Messaging** |
| The resource, seat, or booth being paid for | **Resources** |
| Sponsorship contracts and their billing terms | **Sponsoring** |
| GDPR export and deletion orchestration | **Compliance** — Commerce contributes its own `src/Gdpr/` exporter, constrained by statutory retention for invoices |

## Public contract (events)

Events are the only way other modules may react to this one (ADR-0004).
Payloads carry ULIDs and scalars — never Eloquent models.

Rows still marked **(proposed)** have no code yet; the unmarked rows are
implemented and their payloads are the contract.

| Event | Emitted when | Payload |
|---|---|---|
| `OrderPlaced` | An order is created from a settlement request (carts do not exist yet) | `order_id`, `brand_id`, `settles_type`, `settles_id`, `total_net`, `total_gross`, `currency`, `placed_at` |
| `OrderPaid` | An order is fully settled, by any adapter or by manual booking. **The event Registration consumes** (ADR-0006) | `order_id`, `brand_id`, `settles_type`, `settles_id`, `amount_paid`, `currency`, `paid_at`, `settlement_method` |
| `OrderPartiallyPaid` (proposed) | A payment lands that does not cover the balance | `order_id`, `amount_paid`, `balance_due`, `currency`, `received_at` |
| `PaymentReceived` | Any payment is recorded — a webhook-confirmed PSP payment or a manually booked bank transfer. Distinct from `OrderPaid`: this is about the money that arrived, that one about what it means for the order | `payment_id`, `order_id`, `brand_id`, `amount`, `currency`, `settlement_method`, `external_reference` (nullable — manual bookings have none), `received_at` |
| `PaymentFailed` | An adapter reports a failed or expired attempt (ADR-0039). The order stays pending — a new checkout may follow | `payment_id`, `order_id`, `brand_id`, `settlement_method`, `reason_code`, `failed_at` |
| `SettlementAccountConnected` | A brand finishes connecting its own PSP account (ADR-0007) — from this moment its orders settle through that provider | `settlement_account_id`, `brand_id`, `provider`, `connected_at` |
| `OrderCancelled` (proposed) | An order is cancelled before or after settlement | `order_id`, `cancelled_by_user_id`, `reason_code`, `cancelled_at` |
| `OrderRefunded` | Money went back to the payer. Distinct from `CreditNoteIssued`: that is the document, this is the money. An ADR-0036 transfer issues the first without the second, so a consumer counting refunds must listen here | `order_id`, `brand_id`, `settles_type`, `settles_id`, `amount_refunded`, `currency`, `credit_note_id` (nullable — an uninvoiced order has no document), `reason_code`, `refunded_at` | implemented |
| `InvoiceIssued` | An invoice number is drawn from the brand's range and the invoice is finalized — immutable from this moment (ADR-0031) | `invoice_id`, `order_id`, `brand_id`, `invoice_number`, `issued_on`, `due_on`, `total_gross`, `currency` |
| `InvoiceOverdue` | An issued invoice passes its due date with its order unpaid. Emitted **once per invoice**, not once per sweep — the sweep runs daily and re-emitting would make one unpaid invoice a daily notification about the same fact | `invoice_id`, `invoice_number`, `order_id`, `brand_id`, `total_gross`, `currency`, `due_on`, `days_overdue`, `detected_at` | implemented |
| `DunningLevelRaised` | An overdue invoice reached the next days-overdue threshold. Commerce declares *that* a level was reached; Messaging decides what a level means to a recipient — the same split D-CNT-08 draws for announcements, which is why no mail is sent from here | `invoice_id`, `invoice_number`, `order_id`, `brand_id`, `level`, `days_overdue`, `raised_at`, `billing_name`, `billing_email` (nullable — the contact may have no account), `total_gross`, `currency`, `due_on` (billing scalars added for ADR-0041 point 8: Messaging's listener must not read this module's models) | implemented |
| `CreditNoteIssued` | A credit note was issued against an order. `reason_code` distinguishes a refund (ADR-0037) from a post-invoice transfer (ADR-0036) — a transfer is immediately followed by a new invoice for the same amount, so treating it as a refund would double-count | `credit_note_id`, `credit_note_number`, `order_id`, `invoice_id`, `brand_id`, `total_gross`, `currency`, `reason_code`, `issued_at` | implemented |
| `VoucherRedeemed` (proposed) | A voucher is applied to an order | `voucher_id`, `order_id`, `discount_amount`, `currency`, `redeemed_at` |

## Consumes

Events from other modules that this module listens to, and the read models it
maintains from them.

| Event | Owning module | Why |
|---|---|---|
| `RegistrationSettlementRequested` | Registration | Create the order (and, for `settlement: invoice`, the invoice) from the lines and billing party in the payload — without reading a single Registration model. Lines carry the declared tax *class*; the rate and treatment (reverse charge, §19 UStG) are resolved here at settlement time (ADR-0017, ADR-0030) and snapshotted onto the order lines |
| `RegistrationCancelled` (proposed) | Registration | Cancel the open order, or issue a credit note if an invoice was already issued |
| `UserAnonymizationRequested` (proposed) | Compliance | Hand the request to this module's `src/Gdpr/` handler, which must reconcile deletion with statutory invoice retention |
| `BrandCreated` (proposed) | Platform | Initialize the brand's invoice number range and its (unconnected) settlement account |
| `EventPublished` (proposed) | Event | Maintain the event read model used to group and report revenue |

## Capabilities

Declared in `src/Enums/Permission.php`. Roles that grant them are per-brand
data, not code (ADR-0013).

The catalog covers: viewing orders and invoices, issuing and cancelling
invoices, booking payments manually, issuing refunds and credit notes, running
dunning, managing vouchers, connecting and managing the brand's settlement
account, viewing and managing the product catalogue, and exporting
bookkeeping data. Manual payment booking and settlement account management
are deliberately separate capabilities — one is day-to-day treasury work, the
other changes where a brand's money goes. `commerce.shop-manager` (ADR-0045)
is the shop's own starting role: the two product capabilities plus viewing
orders, without invoicing, refunds or settlement access.

## Activation

**Core tier: always active.** Every brand needs Commerce and no Pennant feature
gates it. The module self-registers through `CommerceServiceProvider` at boot
(ADR-0003).

Always active does not mean always used: a brand running only `settlement:
free` registration types — which is exactly the Phase 1 PoC scope — never
causes an order to exist. Commerce stays dormant until a registration type
declares a settlement that needs money to move.

## Open questions

Zero-value orders are no longer open — see ADR-0026 and the settled note
under "Owns" — `Order`.

1. ~~**Where the price lives.**~~ **Settled by ADR-0017:** the type keeps the
   declaration (value object), Commerce computes line totals, tax and rounding
   and snapshots them onto the order. The catalogue alternative was rejected
   for release; the value object keeps that path open.
2. ~~**Tax model scope.** Which German VAT cases must be supported at release —
   standard and reduced rates, reverse charge for B2B exhibitors, the small
   business exemption, and whether cross-border sales need OSS handling.~~
   **Settled by ADR-0030 (decision log Round 6, D-COM-13):** standard +
   reduced rates, reverse charge for B2B exhibitors holding a VAT ID, §19 UStG
   as an operator flag. OSS is out of release scope.
3. ~~**Invoice document integrity.** PDF rendering, immutability, archival, and
   GoBD-compliant retention — including whether e-invoicing formats (ZUGFeRD /
   XRechnung) are release scope or later.~~ **Settled by ADR-0031 (decision log
   Round 6, D-COM-14):** GoBD-compliant immutable PDF plus archival, issued as
   hybrid ZUGFeRD. XRechnung deferred.
4. ~~**Bank reconciliation.** Whether CAMT.053 / MT940 import is built, or
   manual booking remains the only path for bank transfers until after
   release.~~ **Decision log Round 6 (D-BATCH):** manual booking only at
   release — no CAMT.053 / MT940 import.
5. ~~**Refund policy interaction.** What a refund means when the associated
   admissions have already been checked in — and whether Commerce may express
   any opinion about that, or only report the refund and let Registration
   decide. **The remaining open Commerce question** after the Round 6 gate
   review — every other item on this list is now settled.~~ **Settled by
   ADR-0037 (decision log Round 7, D-COM-15):** a checked-in admission blocks
   the refund. Commerce answers from its own check-in read model, fed by
   Admission events carrying `registration_id` — never by reading Admission
   tables. This was the last open Commerce question; nothing on this list
   remains open.
6. ~~**Instalments.** Whether exhibitor invoices support partial payment plans,
   and how dunning behaves against a plan rather than a single due date.~~
   **Decision log Round 6 (D-BATCH):** not at release.
7. ~~**Multi-currency.** Whether a brand may sell in more than one currency, or
   currency is fixed per brand.~~ **Decision log Round 6 (D-BATCH):** fixed
   per brand.
