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
  colour, or, for a product without variation, the single implicit row
  `SaveProduct` creates so every product has exactly one stock counter. The
  operator console for both lives at `/admin/products`
  (`ProductAdminController`), gated on `commerce.view-products`/
  `commerce.manage-products`. The public storefront is `/shop` and
  `/shop/{slug}` (`ShopController`), behind the per-brand `commerce:shop`
  feature (ADR-0047) with no module gate — a brand that never switches it on
  404s both routes. Only published products are listed; a sold-out one
  (every variant at `remaining() === 0`) still shows, badged, rather than
  disappearing. The product page's buy button posts straight to
  `commerce.cart.lines.store`; a guest sees a sign-in link in its place.
  **Stock is a quota in the Registration sense**: `stock_capacity` and
  `stock_consumed` are counters only, copied from `Quota`'s contract rather
  than imported (ADR-0004) — `consumeStock()`/`releaseStock()` never lock or
  open a transaction themselves, callers do that under their own Action's
  `DB::transaction`. `sku` is unique per brand only when set, so many
  implicit variants with no sku never collide.
- **`Cart` and cart lines** — a person-owned reservation with **two
  lifetimes** (ADR-0047): the first Commerce root that belongs to a person
  before any order exists. While `open`, it locks stock as a quota for
  twenty minutes (`Cart::LIFETIME_MINUTES`); the moment checkout moves it to
  `checked_out`, that same `expires_at` column is reset to a second, longer
  window — sixty minutes (`Cart::CHECKOUT_GRACE_MINUTES`, Task 11 fix round
  1) — the grace a payer gets to finish a hosted checkout page before the
  reservation and the pending order behind it are given up on. A cart is
  never both `open` and `checked_out` at once, so the one column serves
  both windows rather than needing a second.
  `OpenCart::for(brandId, payerUserId)` finds a payer's open cart or opens
  one, `brand_id` set explicitly rather than from ambient context (a queue
  or admin caller has none) — the partial unique index
  `carts_one_open_per_payer` makes a second concurrent one for the same
  payer impossible, the same read-then-write race `entrants_one_live_per_team`
  closes elsewhere. A `CartLine` is either `product` (locks a
  `ProductVariant`'s stock, keyed by its id, merged rather than duplicated
  on a second add of the same variant) or `registration` (ADR-0047 point 3
  — Task 10 creates these, keyed by the settlement request's own
  `request_key` since a person may hold more than one pending registration
  at once). `AddProductToCart`, `ChangeCartLineQuantity` and
  `RemoveCartLine` all lock the variant row under their own transaction and
  call `Cart::touchExpiry()`, so continued activity keeps the reservation
  alive; `ChangeCartLineQuantity` refuses a registration line with a
  `LogicException`, since its quantity is fixed by the registration itself.
  **The sweep** (`commerce:expire-carts`, scheduled every fifteen minutes
  with `->withoutOverlapping()`, mirroring
  `Registration\Actions\ExpireWaitlistOffers`) is two passes over the same
  `expires_at` deadline, each in its own per-cart transaction: the
  **shopping pass** expires every `open` cart past `expires_at` — a
  product line's stock is released silently, a registration line emits
  `CartLineExpired` so Registration can cancel what it was holding, and the
  cart itself moves to `expired`. The **checkout-grace pass** (Task 11 fix
  round 1) does the same release, plus one more step first, for every
  `checked_out` cart past `expires_at` whose order is still `pending` — a
  payer who closed the PSP tab, or whose hosted session lapsed, without
  either the webhook or a return visit ever landing: the order is marked
  `Cancelled` before its lines are released, so the stock and registration
  holds a checkout claimed do not sit there forever waiting for a payment
  that is never coming. A `checked_out` cart whose order is already `paid`
  is never selected by this pass at all — filtered at the query itself, not
  merely skipped once fetched — so it is left alone for good, however long
  `expires_at` has been in the past. A PSP success that lands *after* this
  pass has cancelled the order is refused by
  `RecordPaymentSuccess::settleOrder()`'s existing not-pending guard
  (logged as a warning with the order id and provider, never silently
  dropped) — a refund path for that late-arriving money is a known gap,
  `TODO.md`.
  `GuardsProductVariantReferences::isReferenced()` (Task 7) now also
  refuses to delete a product or drop a variant still held by an *open*
  cart's line — an expired cart's line does not count, since its stock was
  already released. A refund does not return a product line's stock: the
  goods left the shelf, and putting them back is an operator decision made
  by raising `stock_capacity`, not a consequence of the money moving back.

  **The cart page and its checkout** (ADR-0047 point 4, Task 11), on the
  brand's own domain behind `ensure-feature:commerce:shop` + `auth`
  (`CartController`): `GET /cart` (`commerce.cart.show`) lists the payer's
  open cart, a tax preview of its totals, and one settlement-account button
  per offered account (ADR-0046) — an empty state linking to `/shop` when
  there is none. `POST /cart/lines` (`commerce.cart.lines.store`), `PATCH
  /cart/lines/{line}` (`commerce.cart.lines.update`) and `DELETE
  /cart/lines/{line}` (`commerce.cart.lines.destroy`) wrap
  `AddProductToCart`/`ChangeCartLineQuantity`/`RemoveCartLine`; `{line}` is
  resolved through the payer's own open cart, never a bare `CartLine`
  lookup, so another payer's line 404s. `POST /cart/checkout`
  (`commerce.cart.checkout`) is `PlaceOrderFromCart::handle()`: it turns
  the cart into one `Order` (`settles_type = 'cart'`, `settles_id`/
  `request_key` the cart's own id), one `OrderLine` per cart line carrying
  `settles_kind`/`settles_ref` from the line's own `kind`/`ref_id`, moves
  the cart to `checked_out` (resetting `expires_at` to the checkout-grace
  window above), and starts the chosen account's hosted checkout exactly
  like `CheckoutController::start()` does for a direct registration order —
  idempotently: a cart already `checked_out` with a still-pending order
  resumes that order instead of placing a second one, extending its own
  grace window again in the process. Every guard the Action enforces —
  cart open and non-empty, account offered and hosted-checkout-capable —
  throws `CartCheckoutRefusedException`, which the controller alone
  catches and turns into a 409; a genuine PSP adapter failure past those
  guards is left uncaught, reaching the payer the way
  `CheckoutController::start()` already lets one. `Cart::isOpen()` reads
  the status column only — a cart past `expires_at` that the
  fifteen-minute sweep has not reached yet still holds its stock, so
  checking it out is settling a reservation that is genuinely still in
  force; the sweep, not the clock, is the authority.
  The topbar badges the "Cart" nav entry from the shared `cart.count` prop
  (`HandleInertiaRequests`, resolved through Commerce's own `CartSummary`
  service so the host layer never reads a `Cart` row itself).
- **The waiting room** (ADR-0047 point 5, Task 12) — a Redis queue and a
  signed ticket cookie in front of the shop, the cart and Registration's
  public form, applied through Platform's `ensure-waiting-room` route
  middleware alias (Platform owns the alias and the `WaitingRoomGate`
  contract; Commerce binds `CommerceWaitingRoomGate`, the only
  implementation). `WaitingRoomStore` (`src/WaitingRoom/`) is Redis in
  every real environment (`RedisWaitingRoomStore`, bound by
  `config('commerce.waiting_room.store')`) and PHP arrays only for the
  test suite (`ArrayWaitingRoomStore`, `WAITING_ROOM_STORE=array`,
  `phpunit.xml`) — a singleton because it holds no request-scoped state,
  never bound outside tests. Redis keys, per brand: `waiting-room:{brand}
  :queue` (a sorted set, score = arrival `microtime(true)`, `ZPOPMIN` for
  atomic admission), `waiting-room:{brand}:ticket:{id}` (a hash: `url`,
  `user`, `admitted_at`, `expires_at`; 2h TTL), `waiting-room:{brand}:user
  :{userId}` (a ticket id, so a signed-in visitor's second visit reuses
  the same ticket instead of queueing twice), `waiting-room:{brand}:ip
  :{sha256(ip)}` (a counter, 1h TTL, capped at five new tickets — the raw
  IP is never stored). The cap counts `$request->ip()`, which is the
  forwarded address whenever the peer is a trusted proxy — with
  `TRUSTED_PROXIES=*` a client can set `X-Forwarded-For` itself and mint
  unlimited tickets, so the cap is only as strong as the proxy list
  (`config/trustedproxy.php`). `tests/Load/ticket-rush.js` relies on
  exactly this to simulate a thousand distinct arrivals. The cookie
  (`TicketCookie`) is
  `waiting_room_{brandId}`, `httpOnly`, `sameSite: lax`, 30 minutes:
  `id.hash_hmac('sha256', id, config('app.key'))`, parsed back with a
  constant-time signature check — a forged or tampered cookie reads as no
  ticket at all. Declared to the cookie dialog as the necessary,
  pattern-named `waiting_room_*` ("Your place in the queue while a sale
  is busy").
  `CommerceWaitingRoomGate::guard()`'s order: the brand's
  `commerce:waiting-room` feature off → pass; a signed-in user holding
  `commerce.manage-products` on this brand → pass (an operator must never
  queue behind their own rush); an admitted, unexpired ticket → pass;
  otherwise `IssueWaitingRoomTicket::handle()` reuses a still-known ticket
  (from the cookie, then from the user mapping) or mints a new one after
  the per-IP cap, sets the cookie on the response, and redirects to
  `route('commerce.waiting-room.show')`.
  `AdmitFromWaitingRoom::handle(Brand, count)` pops up to `count` tickets
  off the head under `Cache::lock("waiting-room:{brand}", 10)` and stamps
  each one admitted until `now() + WaitingRoomSettings::forBrand()->
  admission_ttl_minutes` — the lock closes the gap between the atomic pop
  and the (separate) stamp, so two overlapping admission ticks cannot
  double-admit a batch. `WaitingRoomSettings` (`admit_per_interval`
  default 50, `admission_ttl_minutes` default 30, one row per brand,
  `forBrand()` the `BookkeepingSettings::forOperator()` idiom) has its own
  small save form — `PUT /admin/brands/{brand}/waiting-room`
  (`WaitingRoomSettingsAdminController`, `commerce.manage-products`, the
  same capability the gate's operator bypass checks) — deliberately not
  folded into `UpdateBrandFeatures`. The brand edit page's Waiting room
  card reaches it through `BrandFeature`'s `settingsComponent`/
  `settingsProps` (Platform's extension point, not a `SlotRegistry` slot —
  see Platform's `SPEC.md`), rendered only while the feature is active.
  **Task 13** built the holding page and the scheduled admission tick.
  `GET /waiting-room` (`commerce.waiting-room.show`, Inertia
  `waiting-room/Show`) and `GET /waiting-room/status`
  (`commerce.waiting-room.status`, JSON `{position, admitted, redirectTo,
  depth}`) — `WaitingRoomController` — sit behind
  `ensure-feature:commerce:waiting-room` only: **not**
  `ensure-waiting-room` (the page IS the waiting room; gating it behind
  itself would loop) and **not** `auth` (a guest queues like anyone
  else). A visitor with no valid ticket cookie is sent to `/shop`, which
  the gate re-issues one from; one already holding an admitted, unexpired
  ticket who revisits is sent straight on to its `intendedUrl` rather than
  shown a stale position. The page polls `status` every five seconds (a
  local `fetchJson` copied from `NotificationBell.vue`, GET only) and
  navigates away the moment it reports `admitted`; a `<noscript>` meta
  refresh covers a visitor with JavaScript disabled. `AdmitWaitingRoomBatch`
  (`src/Jobs/`, queue `waiting-room`, `ShouldBeUnique`, `uniqueFor` 5s)
  resolves the brand, checks `commerce:waiting-room` is still active, calls
  `AdmitFromWaitingRoom` with `WaitingRoomSettings::forBrand()->
  admit_per_interval`, writes `waiting-room:{brand}:last_admission` via
  the store's `recordAdmissionRun()`/`lastAdmissionAt()` (added to
  `WaitingRoomStore` alongside the rest, both implementations) whether or
  not anyone was admitted, and records `Pulse::record('waiting_room_admitted',
  $brandId, $count)->sum()` when `$count > 0`. Its `uniqueId()` folds the
  job's own `->delay()` into the lock key rather than the brand id alone
  — Laravel checks a `ShouldBeUnique` job's lock at dispatch time, before
  any delay elapses, so a literal brand-only key would let only the first
  of a tick's twelve staggered dispatches through; see the class's own
  docblock. `commerce:waiting-room-tick` (`src/Console/WaitingRoomTick.php`,
  scheduled `everyMinute()->withoutOverlapping()` beside the cart sweep)
  walks every brand — no `BrandContext`, there is none outside a request —
  and for each with the feature active and `depth() > 0` dispatches twelve
  batches five seconds apart, spreading one minute's admission instead of
  bursting it. Horizon's `waiting-room` supervisor (`config/horizon.php`,
  `maxProcesses` 2 in `local`/`production`) drains that queue on its own,
  away from `supervisor-1`'s traffic. `WaitingRoomCheck`
  (`src/Health/`, a `Spatie\Health\Checks\Check`, registered in
  `App\Providers\ObservabilityServiceProvider::registerHealthChecks()`
  the same way `HorizonCheck` is) fails when some feature-on brand has
  `depth() > 0` and no admission run in the last two minutes — proof the
  tick is still ticking, not merely scheduled. A Pulse dashboard card was
  scoped out this task: it would need a Livewire component, its own view,
  a Livewire registration, and a publish-and-edit of `dashboard.blade.php`
  (never published in this app), past the "one class, one view" bar the
  task set — the recording exists, the card is `TODO.md` backlog.
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

  **`orders.billing_party`** (ADR-0048, nullable JSON `BillingPartyData`
  cast) — who an invoice for this order would address, snapshotted at
  placement: `PlaceOrderFromSettlementRequest` stores the settlement
  request's own party for every settlement kind, not only `invoice`;
  `PlaceOrderFromCart` stores `BillingPartyData::ofAccount()` — the payer's
  account name and email, nothing more, since a shop checkout gathers
  nothing else. Null on an order placed before ADR-0048, or one an
  operator books manually with no billing information — `IssueInvoiceOnOrderPaid`
  treats that as nothing to issue, logged, not guessed at.
- **Pricing and tax calculation** — line totals, tax rates, and the net-vs-gross
  *display* decision, which Commerce applies from the flag declared on the
  `RegistrationType` (ADR-0006).
- **`Invoice`, credit notes, and cancellations** — including **per-brand
  invoice number ranges**, which are legally cleaner when brands appear as
  separate identities (ADR-0001).

  **Every paid order gets a Rechnung, at the moment it is paid (ADR-0048).**
  `IssueInvoiceOnOrderPaid` listens to `OrderPaid` and issues an invoice in
  the same gapless per-brand series `IssueInvoice` already draws for
  invoice-settled registrations — `due_on = issued_on` (nothing is still
  owed) and a payment snapshot (`paid_at`, `paid_via`, `paid_provider`,
  `payment_reference`) written at the same moment as every other field
  (ADR-0031 immutability). `IssueInvoice::handle()` is idempotent per
  `order_id`: it locks the order row (`lockForUpdate`) *inside* its own
  transaction before checking for an existing invoice, so two concurrent
  calls for the same order — a replayed `OrderPaid`, or two manual-booking
  double-submits, which take no lock of their own before calling in —
  serialize instead of both drawing a number; the listener repeats the
  same existence check itself as a second line of defence. An order with
  no `billing_party` is logged and skipped: pre-ADR-0048 data, or an
  operator-booked order with nothing to address an invoice to. `paid_via`
  is the human-readable settlement label (`Settlement\ProviderLabels`:
  `stripe → 'Stripe'`, `manual → 'Überweisung / manuell'`, `null →
  'Testzahlung'` — the `local`/`testing`-only no-PSP-connected adapter,
  …); `paid_provider` is the raw settlement key it was derived from
  (`Payment::provider` / `OrderPaid::settlementMethod`), kept alongside it
  because `InvoiceDocumentRenderer` needs a stable key to match against,
  not a translated, operator-overridable label. The rendered document
  states that it is paid — `InvoiceDocumentRenderer`'s Blade half prints
  "Bezahlt am … via …" instead of "Zahlbar bis …" (or "Fällig am …"), the
  Factur-X XML carries `addDocumentPaymentMean()` (SEPA credit transfer
  for `manual`, bank card for `stripe`/`mollie`/`unzer`, matched off
  `paid_provider`) and `setDocumentSummation()`'s `totalPrepaidAmount`
  equal to the gross total with `duePayableAmount` zero, and drops
  `addDocumentPaymentTerm()`'s due term entirely rather than let the XML
  disagree with the PDF. It is archived and mailed exactly like an open
  invoice — one series, one renderer, no separate receipt document type.
  The invoice mail is **two message template keys sharing one payload**
  (`Data\InvoiceMailData`) rather than one template branching on `$paid`:
  `commerce.invoice-issued` (open, unchanged wording) and
  `commerce.invoice-paid` (ADR-0048) — a paid Rechnung's subject reads
  "…– bezahlt" / "…– paid" and its body thanks the payer for the payment
  already received instead of naming a due date. `SendInvoiceMail` picks
  the key from `Invoice::isPaid()`; a per-brand override of either wording
  stays a plain template with no conditional of its own.

  **A Gutschrift gets a document too, at the moment it is issued
  (ADR-0048 point 2).** `IssueCreditNote` dispatches
  `Jobs\GenerateCreditNoteDocument` `afterCommit()`, mirroring
  `IssueInvoice`'s document dispatch exactly: `Documents\CreditNoteDocumentRenderer`
  renders the same hybrid-ZUGFeRD shape (`ZugferdInvoiceType::CREDITNOTE`,
  code 381), archived once to the `credit_notes.document_*` columns
  (idempotent on `document_path`, same as an invoice) on the same
  `invoices_disk` an invoice archives to — one archive, one retention rule,
  for both document kinds. Unlike `InvoiceDocumentRenderer`, it never reads
  `Order`/`OrderLine`: a credit note only snapshots the invoice's totals
  (`IssueCreditNote`), so the document renders one summary position
  ("Gutschrift zu Rechnung Nr. …") rather than a line-by-line breakdown, and
  `addDocumentInvoiceReferencedDocument()` carries the corrected invoice's
  number and issue date whenever `invoice_id` is set. `Actions\SendCreditNoteMail`
  mails the sealed archive through `commerce.credit-note-issued`
  (`Data\CreditNoteMailData`) — one template for both reasons
  (`IssueCreditNote::REASON_REFUND`/`REASON_TRANSFER`): the payload carries
  `reasonLabelDe`/`reasonLabelEn` as two already-localised strings rather
  than a raw `reasonCode` the template branches on, so the body stays
  branch-free the same way the invoice templates do. The payer's own copy
  is `GET /my/credit-notes/{creditNote}/document`
  (`commerce.my.credit-note.document`, `MyCreditNoteDocumentController`) —
  `auth` + `signed:relative` like the invoice route, but with an *explicit*
  `payer_user_id` ownership check inside the controller: a credit note has
  no per-render minting site that has already proven ownership the way
  `RegistrationSettlementData::ofOrder()` does for an invoice, since
  `MyOrderData::ofOrder()` mints every credit note's link from the same
  "my orders" list every payer can reach. `MyOrderData.creditNotes`
  (`Data\MyCreditNoteData`, additive) lists every Gutschrift issued against
  an order, oldest first, each with its own `documentUrl`; the operator
  console's twin, `admin.credit-notes.document`
  (`Admin\CreditNoteDocumentController`), is gated on `ViewOrders` — Commerce
  has no separate "view credit notes" capability, and the order admin page
  already lists credit notes under that same permission.
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
| `OrderPlaced` | An order is created from a settlement request — directly from `PlaceOrderFromSettlementRequest`, or from `PlaceOrderFromCart` at the end of a cart checkout (`settles_type = 'cart'`, ADR-0047 point 4, Task 11) | `order_id`, `brand_id`, `settles_type`, `settles_id`, `total_net`, `total_gross`, `currency`, `placed_at` |
| `OrderPaid` | An order is fully settled, by any adapter or by manual booking. **The event Registration consumes for a direct (non-cart) order** (ADR-0006) — a cart order's registration lines are `OrderLineSettled`'s job instead, below. **Commerce also listens to its own event** (`IssueInvoiceOnOrderPaid`, ADR-0048): issues the paid Rechnung, synchronously inside `RecordPaymentSuccess::settleOrder()`'s own transaction | `order_id`, `brand_id`, `settles_type`, `settles_id`, `amount_paid`, `currency`, `paid_at`, `settlement_method`, `settlement_account_id` (nullable — manual bookings have none) |
| `OrderPartiallyPaid` (proposed) | A payment lands that does not cover the balance | `order_id`, `amount_paid`, `balance_due`, `currency`, `received_at` |
| `PaymentReceived` | Any payment is recorded — a webhook-confirmed PSP payment or a manually booked bank transfer. Distinct from `OrderPaid`: this is about the money that arrived, that one about what it means for the order | `payment_id`, `order_id`, `brand_id`, `amount`, `currency`, `settlement_method`, `external_reference` (nullable — manual bookings have none), `received_at`, `settlement_account_id` (nullable — manual bookings have none) |
| `PaymentFailed` | An adapter reports a failed or expired attempt (ADR-0039). The order stays pending — a new checkout may follow | `payment_id`, `order_id`, `brand_id`, `settlement_method`, `reason_code`, `failed_at`, `settlement_account_id` (nullable — manual bookings have none) |
| `SettlementAccountConnected` | A brand finishes connecting its own PSP account (ADR-0007) — from this moment its orders settle through that provider | `settlement_account_id`, `brand_id`, `provider`, `connected_at` |
| `OrderCancelled` (proposed) | An order is cancelled before or after settlement | `order_id`, `cancelled_by_user_id`, `reason_code`, `cancelled_at` |
| `OrderRefunded` | Money went back to the payer. Distinct from `CreditNoteIssued`: that is the document, this is the money. An ADR-0036 transfer issues the first without the second, so a consumer counting refunds must listen here | `order_id`, `brand_id`, `settles_type`, `settles_id`, `amount_refunded`, `currency`, `credit_note_id` (nullable — an uninvoiced order has no document), `reason_code`, `refunded_at` | implemented |
| `InvoiceIssued` | An invoice number is drawn from the brand's range and the invoice is finalized — immutable from this moment (ADR-0031) | `invoice_id`, `order_id`, `brand_id`, `invoice_number`, `issued_on`, `due_on`, `total_gross`, `currency` |
| `InvoiceOverdue` | An issued invoice passes its due date with its order unpaid. Emitted **once per invoice**, not once per sweep — the sweep runs daily and re-emitting would make one unpaid invoice a daily notification about the same fact | `invoice_id`, `invoice_number`, `order_id`, `brand_id`, `total_gross`, `currency`, `due_on`, `days_overdue`, `detected_at` | implemented |
| `DunningLevelRaised` | An overdue invoice reached the next days-overdue threshold. Commerce declares *that* a level was reached; Messaging decides what a level means to a recipient — the same split D-CNT-08 draws for announcements, which is why no mail is sent from here | `invoice_id`, `invoice_number`, `order_id`, `brand_id`, `level`, `days_overdue`, `raised_at`, `billing_name`, `billing_email` (nullable — the contact may have no account), `total_gross`, `currency`, `due_on` (billing scalars added for ADR-0041 point 8: Messaging's listener must not read this module's models) | implemented |
| `CreditNoteIssued` | A credit note was issued against an order. `reason_code` distinguishes a refund (ADR-0037) from a post-invoice transfer (ADR-0036) — a transfer is immediately followed by a new invoice for the same amount, so treating it as a refund would double-count | `credit_note_id`, `credit_note_number`, `order_id`, `invoice_id`, `brand_id`, `total_gross`, `currency`, `reason_code`, `issued_at` | implemented |
| `VoucherRedeemed` (proposed) | A voucher is applied to an order | `voucher_id`, `order_id`, `discount_amount`, `currency`, `redeemed_at` |
| `StockExhausted` | A `ProductVariant`'s `remaining()` reaches zero from a `consumeStock()` call — the transition into sold-out, not every purchase against an already-exhausted variant | `variant_id`, `product_id`, `brand_id`, `exhausted_at` |
| `CartExpired` (ADR-0047) | The sweep moves an open cart past `expires_at` to `expired`, after every one of its lines has been released or announced | `cart_id`, `brand_id`, `payer_user_id`, `expired_at` |
| `CartLineExpired` (ADR-0047) | The sweep expires a registration-kind cart line — a product line releases its stock silently instead. **Registration consumes this** to cancel the still-pending registration the line was holding | `cart_id`, `brand_id`, `kind`, `ref_id`, `request_key` (nullable), `expired_at` |
| `CartLineRemoved` (ADR-0047) | A person removes a registration-kind cart line — a product line releases its stock silently instead. **Registration consumes this**, the same way it consumes `CartLineExpired` | `cart_id`, `brand_id`, `kind`, `ref_id`, `request_key` (nullable), `removed_at` |
| `CartCheckedOut` (ADR-0047, Task 11) | `PlaceOrderFromCart` moves a cart from `open` to `checked_out` and names the `Order` it produced. Fired alongside `OrderPlaced`, inside the same transaction — nothing consumes it yet; it exists for "this cart is spoken for" without reading `Cart` | `cart_id`, `brand_id`, `payer_user_id`, `order_id`, `checked_out_at` |
| `OrderLineSettled` (ADR-0047, Task 11) | One order line was paid — emitted per line from `RecordPaymentSuccess::settleOrder()`, alongside `OrderPaid`, **only for an order whose `settles_type` is `cart`**. A direct order (`settles_type: 'registration'`) has exactly one thing to confirm and `OrderPaid` already says which; only a cart checkout's order settles more than one thing at once, so only it needs a per-line event. **Registration consumes this** (`RecordPaymentOnOrderLineSettled`) to confirm exactly the `kind: 'registration'` lines that were paid — a `kind: 'product'` line is Commerce's own business | `order_id`, `brand_id`, `kind`, `ref_id`, `order_request_key` (the *order's own* `request_key`, ADR-0036 — the checked-out cart's id, not the per-line settlement request key `CartLineExpired`/`CartLineRemoved` carry), `amount_gross`, `currency`, `paid_at`, `settlement_account_id` (nullable) |

## Consumes

Events from other modules that this module listens to, and the read models it
maintains from them.

| Event | Owning module | Why |
|---|---|---|
| `RegistrationSettlementRequested` | Registration | Create the order (and, for `settlement: invoice`, the invoice) from the lines and billing party in the payload — without reading a single Registration model. Lines carry the declared tax *class*; the rate and treatment (reverse charge, §19 UStG) are resolved here at settlement time (ADR-0017, ADR-0030) and snapshotted onto the order lines. **Cart line when shop + instant, else order (ADR-0047, Task 10):** an `instant` settlement on a brand with `commerce:shop` active becomes one `registration`-kind `CartLine` in the registrant's own cart instead — the registration already exists and already holds its quota, only where the money settles changes; every other case (`invoice`, or the feature off) still gets the direct order this listener always produced |
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
