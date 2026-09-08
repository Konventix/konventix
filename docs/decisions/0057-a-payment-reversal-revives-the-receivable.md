# ADR-0057: A payment reversal revives the receivable — the sale stands, the door does not ask

**Status:** accepted (2026-09-07, decision for #166; it settles the
"Rückbuchungen" criterion of #71 and the late-payment gap of #6)

## Context

With Mollie and Stripe's dynamic payment methods come SEPA direct debit and
PayPal, and with them money that leaves *after* `OrderPaid`: a
Rücklastschrift days after the debit, a card chargeback weeks after the
sale, a PayPal dispute the buyer opens from their own account. ADR-0039
gave `Payment` four states — pending, succeeded, failed, expired — and made
the webhook the only source of settlement truth; ADR-0037 settled the
*voluntary* refund, the one the operator starts. For the involuntary
reversal there is no state, no event and no reaction, and by the time it
arrives everything downstream has already happened: the registration is
confirmed, the admission issued, the Rechnung sealed with "bezahlt am"
(ADR-0048) and immutable (ADR-0031).

What the code does today, read rather than assumed:

- `StripeWebhookController` matches four `checkout.session.*` events and
  answers 200 to everything else — a `charge.dispute.created` is received,
  acknowledged and forgotten.
- `MollieWebhookController` and `UnzerWebhookController` return before
  reading any payment that is not `pending`. Mollie notifies a chargeback
  on the *paid* payment, Unzer moves the payment to state `chargeback`;
  both notifications are dropped by the guard that protects the settlement
  path against replays. Unzer's `chargeback` state is a code comment.
- `OrderRefunded` has no consumer. Registration's SPEC lists it as
  proposed ("decide whether the contractual act is cancelled too");
  Admission's SPEC lists `RegistrationCancelled` as proposed input and
  `AdmissionRevoked` as proposed output; `AdmissionState::Revoked` has no
  producing Action. So even the refund ADR-0037 governs leaves the
  registration confirmed and the admission valid. The reversal is the
  sharper form of a gap that is already there.
- The neighbouring case (#6): a PSP success for an order the
  checkout-grace sweep already cancelled is refused by
  `RecordPaymentSuccess::settleOrder()` and logged as a warning. The money
  moved; nothing here records it.

The decision has to hold inside the constraints already on record: modules
talk through events only (ADR-0004); the door asks "is this admission
valid?", never "is it paid?", and a registration's confirmation state and
payment state are independent fields (ADR-0005); the platform is never in
the flow of funds, so a chargeback and its fee land on the organizer's own
account (ADR-0007); an issued document is never edited (ADR-0031,
ADR-0048); the bookkeeping export books documents, never payments
(ADR-0040).

## Decision

1. **A reversal is a record of its own, not a state on the payment.**
   `payment_reversals` rows carry: `payment_id`, `order_id`, `brand_id`,
   `provider`, `settlement_account_id`, `kind`, `status`, `amount`,
   `currency`, `provider_reference` (the dispute or chargeback id),
   `reason_code` (the provider's own string, stored verbatim),
   `fee_amount` (nullable, as reported), `occurred_at`, `finalized_at`
   (nullable) and `repay_due_on` (nullable). The row is unique per
   `(provider, provider_reference)`, so a replayed notification converges
   the way ADR-0039 point 3 makes payments converge — the idempotency #71
   asks for. `PaymentStatus` gains `Reversed`, written only when a reversal
   becomes final for the payment's whole amount, so every existing "did
   this pay?" question stays a one-column read; the row says why and how
   much.

   Three kinds, two moments:

   - **`return`** — final the moment it arrives. A SEPA Rücklastschrift, a
     Mollie chargeback (`chb_…`), Unzer's `chargeback` state, a Stripe
     dispute that is born `lost` (Stripe's shape for a SEPA debit return:
     no evidence can be filed, the dispute closes as it opens).
   - **`dispute`** — open on arrival; the provider withholds the money and
     decides later. Stripe `charge.dispute.created` in any status but
     `lost`; `charge.dispute.closed` makes it `final` (lost) or `withdrawn`
     (won). An open dispute produces the operator notice and the console
     entry and nothing else: the money may come back, and the person may
     legitimately be at the event meanwhile. Evidence is filed in the
     provider's dashboard, which the organizer keeps (ADR-0039 point 5),
     not here.
   - **`external_refund`** — a refund the operator made in the provider's
     dashboard instead of through `RefundOrder`: Stripe `charge.refunded`
     with no refund of ours behind it, Mollie's `amountRefunded` above what
     we asked for. Final on arrival, but its reaction is the *refund's*,
     not the receivable's: the sale is undone, the order becomes
     `refunded`, the credit note is issued and `OrderRefunded` fires,
     exactly as if `RefundOrder` had run minus the provider call. It is the
     operator's own decision, made in the wrong place; the check-in guard
     of ADR-0037 cannot run after the fact and the notice says so.

   A **partial** reversal (Mollie allows one) creates the row and the
   notice, moves neither payment nor order, and is an operator case until
   #35's partial payments exist: there is no amount the order could be
   settled *for* today.

2. **`PaymentReversed` is the cross-domain contract.** Emitted once, from
   `RecordPaymentReversal`, when a reversal becomes final — a return on
   arrival, a dispute on loss — with scalars only: `reversal_id`,
   `payment_id`, `order_id`, `brand_id`, `settles_type`, `settles_id`,
   `kind`, `amount`, `currency`, `settlement_method`,
   `settlement_account_id`, `provider_reference`, `reason_code`,
   `repay_due_on`, `reversed_at`. For a cart order Commerce additionally
   emits `OrderLineReversed` per registration-kind line, the inverse of
   `OrderLineSettled`, so Registration's per-line listener has its mirror.
   Open and withdrawn disputes emit nothing across the boundary; the row
   and the notice carry them.

3. **The sale stands; the receivable revives.** A final reversal moves the
   order from `paid` to `OrderStatus::PaymentReversed`: it was paid, and
   the amount is owed again. Not back to `pending` — `pending` is what the
   checkout-grace sweep cancels after sixty minutes and what "never paid"
   means on every screen. `RecordPaymentSuccess` settles a
   `payment_reversed` order exactly as it settles a pending one, so the
   money comes back by the pay link (#5), the door POS (ADR-0018,
   `BookManualPayment`) or a bank transfer (#162), and each of them ends
   in `OrderPaid` with nothing new to teach downstream.

   No credit note. The Rechnung documents a sale that happened and is
   unchanged; the PDF that says "bezahlt" was true when it was sealed
   (ADR-0048) and stays as it is (ADR-0031). The receivable lives on the
   reversal row, not on a document. The bookkeeping export keeps booking
   documents only (ADR-0040): the reversal, like the payment it undoes,
   reaches the advisor on the PSP statement. The provider's chargeback fee
   is the organizer's cost on that same statement (ADR-0007) — never a line
   on our invoice. Whether to pass it on to the payer is a business
   decision this ADR does not make and no code takes.

4. **A deadline, then a cancellation — through the order.** The reversal
   row carries `repay_due_on` = `reversed_at` plus the brand's repayment
   window, a Commerce setting beside the waiting-room settings, default
   seven days. The operator may cancel the order by hand at any point
   before it. After it, a daily sweep (`commerce:cancel-unpaid-reversals`,
   the shape of the dunning sweep) cancels the order: `OrderStatus::
   Cancelled` with reason `reversal_unpaid`, a credit note with reason
   `cancellation` where an invoice exists — so document and
   counter-document sum to zero and the money side (payment in, return
   out) is the statement's — and `OrderCancelled`, the event Commerce's
   SPEC has proposed since ADR-0006, now with a producer and a
   `reason_code` that says why.

   The sweep skips an order with a checked-in admission, read from
   Commerce's own `CheckedInAdmission` read model exactly as `RefundOrder`
   does (ADR-0037): someone who attended owes money, and that is a
   receivable, not a cancellation. Such an order's invoice enters the
   ordinary dunning ladder as an open receivable, its `overdue_at` set to
   the deadline it missed.

5. **Registration stays confirmed until the deadline; its payment status
   tells the truth.** On `PaymentReversed` / `OrderLineReversed`
   Registration sets `payment_status` back to `pending` and emits
   `RegistrationPaymentStatusChanged`; `status` stays `confirmed` and the
   quota stays held. ADR-0005's two independent fields exist for exactly
   this case: a bank error two days before the event does not throw a
   confirmed person out. On `OrderCancelled` with reason `reversal_unpaid`
   Registration cancels through `CancelRegistration` (reason code
   `payment_reversed`), which releases the quota — `QuotaReleased`, the
   waitlist promotes — and emits `RegistrationCancelled`, whose mail
   Registration already owns (ADR-0048). That is the SPEC's proposed
   "`OrderCancelled` → decide whether the contractual act is cancelled
   too" row, answered: yes, for this reason. The same listener consumes
   `OrderRefunded` for a *whole-order* refund — `OrderRefunded` gains a
   boolean `whole`, since its amount alone cannot say — and cancels the
   registration the same way: the gap ADR-0037 left open closes here.
   Registration never reads a reversal row.

6. **Admission never learns of money.** No listener on `PaymentReversed`
   — that would be the door asking "is it paid?" through the back door,
   which ADR-0005 forbids and lessons-from-lancore #12 warns against.
   Admission consumes `RegistrationCancelled` and revokes the admissions
   that registration produced: `AdmissionState::Revoked`,
   `AdmissionRevoked`, and the entrance delta feed of ADR-0033 — all
   catalogued in its SPEC, now with a producer. Between reversal and
   deadline the admission is valid; the person gets in, and the door POS
   is where the money is collected. A person already checked in keeps the
   admission: attendance happened, and the reversal is point 4's
   receivable.

7. **Each adapter maps its provider's shape, in the controller, the way
   settlement is mapped today.** Stripe from the signed event —
   `charge.dispute.created`, `charge.dispute.closed`, `charge.refunded` —
   correlated through `provider_transaction_id`, the payment intent
   recorded at settlement; `funds_withdrawn` and `funds_reinstated` are
   informational and ignored. Mollie and Unzer by fetch-back (ADR-0039
   point 2): the controllers stop returning early on a succeeded payment
   and instead re-read its chargebacks and refunds — `MolliePaymentSnapshot`
   grows `amountChargedBack`, `amountRefunded` and the chargeback ids,
   `UnzerPaymentSnapshot` the chargeback transactions. All three call one
   Action, `RecordPaymentReversal`, idempotent under the unique key and a
   row lock, the sibling of `RecordPaymentSuccess`. `SettlementAdapter`
   is unchanged. The scheduled reconciliation #71 introduces asks the
   provider the same question the webhook answers, so a lost dispute
   notification is found by the sweep, not by the payer.

8. **Operator and payer both see it.** The order shows its reversals; the
   orders console gains an "open reversals" list; the operator notice
   `commerce.operations-notice` gains the kinds `payment-reversed` and
   `dispute-opened`; the payer receives `commerce.payment-reversed` —
   amount, the provider's reason in plain words, the deadline, the pay
   link, and the bank details where the brand offers transfer. Both are
   Commerce's mails, because Commerce owns the event (ADR-0048 point 3).
   The reason strings are bank and network codes; the mail informs, it
   does not accuse.

9. **The late success is the mirror case (#6).** A success arriving for an
   order that is `cancelled` or `expired` records the payment as
   `succeeded` — the money moved — and lists it beside the reversals under
   the same console view as *unapplied*. It is not refunded automatically:
   the operator may prefer to reinstate the registration while the quota
   allows, a flow this ADR does not build. The operator refunds it through
   the payment's own account, `RefundOrder`'s provider leg without a
   credit note — a cancelled cart order has no document to correct.

### Alternatives considered

**`PaymentStatus::Reversed` alone, no row.** Loses the provider reference,
the fee, the reason and the dispute's lifecycle; a won dispute would have
to flip a terminal state back to `succeeded`; a partial reversal does not
fit a status at all. Rejected — the same reasoning that made `Payment`
a first-class record in ADR-0039.

**Cancel immediately on reversal, seat back to the pool at once.** Most
SEPA returns are insufficient funds or a wrong mandate, fixed the day the
payer is told; immediate cancellation punishes the bank error and hands the
seat to the waitlist a week earlier at the cost of a confirmed attendee.
Rejected as the default; the window is per brand and the operator can
cancel by hand at any time.

**Revoke the admission on reversal, reinstate on payment.** The door would
be evaluating payment, ADR-0005's exact prohibition; offline devices
(ADR-0033) would carry stale revocations for people who have since paid;
and the reinstated admission would need a new token in a wallet that still
holds the old one. Rejected.

**A credit note on reversal.** A credit note says the sale is undone; a
chargeback says the payer took the money back. Booking one would make the
books say "no sale" while a receivable exists, and a later repayment would
need a third document. Rejected — the credit note belongs to the
cancellation, not the reversal.

**Registration or Admission reading reversal rows; Commerce cancelling a
registration.** ADR-0004. Rejected without discussion.

## Consequences

- New: the `payment_reversals` table; `PaymentStatus::Reversed` and
  `OrderStatus::PaymentReversed`; the events `PaymentReversed` and
  `OrderLineReversed`, and producers for the long-proposed
  `OrderCancelled` and `AdmissionRevoked`; `RecordPaymentReversal`; the
  deadline sweep; one brand setting; two notice kinds; one payer mail; the
  console list. Registration gains listeners for `PaymentReversed`,
  `OrderLineReversed`, `OrderCancelled` and `OrderRefunded`; Admission one
  for `RegistrationCancelled`. `OrderRefunded` widens by `whole`.
- The Mollie and Unzer controllers stop dropping every notification on a
  non-pending payment; the guard that protected settlement against replays
  moves into `RecordPaymentSuccess` and `RecordPaymentReversal`, where it
  belongs. The three snapshots grow. `ExpireCarts` is untouched: it
  filters `pending`, and a `payment_reversed` order is not pending.
- Tests owed by #166's acceptance: replay of the same reversal, a return
  before and after check-in, a dispute opened then won, an external
  refund, the deadline sweep with and without a checked-in admission, and
  the late success recorded rather than logged.
- `Payment::latestProviderByOrder()` and the refund guards read
  `succeeded`; a reversed payment is not refundable, and there is nothing
  to refund. The order's detail page has to show a reversed payment as
  what it is rather than hide it as "not succeeded".
- Left open on purpose: passing the provider's fee on to the payer;
  reinstating a cancelled registration when late money arrives; a deadline
  for partial reversals, which wait for #35; whether the repayment window
  should be capped at the event's first day — Commerce does not know the
  event date, and the operator has the manual cancel until it does.
- ADR-0037 and ADR-0039 gain a pointer here; the Commerce, Registration
  and Admission SPEC event tables move their proposed rows to implemented
  as the pieces land, and the commerce `TODO.md` entry for the late
  payment closes into #6.
