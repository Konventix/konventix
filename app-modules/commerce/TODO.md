# Commerce — TODO

> Living document. Update it in the same PR that changes the code: check off
> what you completed, add follow-ups you discovered. Do not generate status
> dashboards, percentages, or summary tables here.

## Next (Phase 1 PoC — no payment; keep Commerce dormant and prove the boundary)

(nothing outstanding for Phase 1 — see Done)

## Backlog (Phase 2 — release scope, invoice-settled registrations are a definition-of-done item)

- [ ] A PSP success arriving for an order already cancelled by
      `ExpireCarts`' checkout-grace sweep (Task 11 fix round 1) is refused
      by `RecordPaymentSuccess::settleOrder()` — logged as a warning, not
      silently dropped, but the money still moved on the provider's side
      with no domain record of it. A refund path for that late payment is
      owed: today an operator would have to notice the log and refund by
      hand through the provider's own dashboard.
- [ ] Bookkeeping export follow-ups (ADR-0040 accepts all of these):
      deviating fiscal years are unsupported (WJ-Beginn is hardcoded to
      January 1); no per-customer debtor master export (collective debtor
      only); `Leistungsdatum` is not filled (the advisor may want the event
      date rather than the invoice date for period assignment — ask them);
      no SKR04 one-click preset on the settings form.
- [ ] Mollie/Unzer key validation at connect time: the key is stored and
      trusted; a typo surfaces at the first checkout, not in the connect
      form. Validate with a cheap authenticated API call before saving.
- [ ] The checkout page pays the *latest* order for a registration
      (ADR-0036): if an unpaid initial order coexists with an unpaid
      amendment delta, only the newest is reachable. Fine while amendments
      require a confirmed (settled) registration; revisit with per-order
      pay links if that changes.
- [ ] Local-dev webhooks: Mollie/Unzer webhook URLs are built from
      `app.url`, which providers cannot reach from a WSL dev stack — needs
      a tunnel for end-to-end manual testing. Stripe can replay via
      `stripe listen --forward-to`.
- [ ] Credit-note PDF. `CreditNote` records the document but nothing renders
      it; ADR-0031's hybrid ZUGFeRD pipeline should extend to credit notes,
      with `InvoiceDocumentRenderer` as the shape to follow.
- [ ] Partial credit notes. `IssueCreditNote` accepts a `$totalGross`
      override, but the tax split is still the invoice's — a genuine partial
      needs its own line-level calculation through `TaxCalculator`.
- [ ] Cancellation as a domain flow (distinct from refund, which is built):
      `OrderCancelled` is still proposed.
- [ ] Vouchers.
- [ ] GoBD retention lifecycle for the `invoices` disk: the archive must
      survive brand deletion, operator changes, and storage migrations for
      ten years (ADR-0031 names the requirement; only the write-once
      archive itself is built). Production needs the disk pointed at
      durable storage — ADR-0043 makes that an S3 driver choice (`invoices_s3`,
      with `document_disk` sealed per invoice and `storage:migrate` verifying
      each copy against the GoBD sha256) and puts Object Lock/versioning on
      the deployment checklist, since Garage implements neither.
- [x] Retention *reporting* (ADR-0043 point 9): `commerce:invoice-retention`,
      scheduled daily at 07:15 and logged at INFO. Deliberately no automated
      deletion and no startup check — under Octane "startup" is every worker
      boot. What deletion means (§147 AO counts ten years from the end of the
      calendar year of issue) stays deferred to its own decision, tracked in
      ROADMAP Phase 3.
- [ ] VIES validation for reverse-charge VAT IDs — ADR-0030 accepts the
      unvalidated-ID gap at release; the organizer's bookkeeper must be told.
      Revisit before any real cross-border exhibitor volume.
- [ ] `OrderPartiallyPaid` / `PaymentReceived` as first-class payment records
      — `BookManualPayment` currently books full settlement only.
- [ ] Invoice-number prefix settings UI: the per-(brand, year) range row
      carries the prefix (defaulting from the brand slug on first draw);
      editing it needs a settings screen and only ever applies to future
      ranges.
- [ ] Pulse card for the waiting room is owed (Task 13). The
      `waiting_room_admitted` metric is recorded (`AdmitWaitingRoomBatch`,
      `Pulse::record(...)->sum()`); nothing renders it yet. Needs a
      Livewire component extending `Laravel\Pulse\Livewire\Card`
      (`#[Lazy]`, `$this->aggregate('waiting_room_admitted', ['sum'])`),
      its Blade view (`commerce::pulse.waiting-room`, registered via
      `loadViewsFrom`), a `Livewire::component()` registration, and —
      since `resources/views/vendor/pulse/dashboard.blade.php` has never
      been published in this app — `php artisan vendor:publish
      --tag=pulse-dashboard` followed by adding the card's
      `<livewire:.../>` line, with the ongoing cost of keeping that
      published copy in step with Pulse's own default on every upgrade.
- [ ] Refunding a cart order leaves its product lines' stock_consumed raised on purpose (SPEC, Cart) — if operators keep raising capacity by hand after refunds, give RefundOrder an explicit "return to shelf" option per product line.
- [ ] The shop renders a product's net amount even when price_display is gross — honest only because it is captioned "excl. VAT". A real gross display needs ShopProductData to carry a server-computed unitAmountGross from TaxCalculator (no billing party, so ADR-0030's domestic-standard treatment), the way the cart page already computes its totals.
- [ ] PlaceRegistrationLineInCart's read-then-insert can lose a race to cart_lines_request_key_unique and throw a QueryException out of a listener, the same shape PlaceOrderFromSettlementRequest carries. The 2026-08-27 rush never surfaced it (0 × 5xx across 754 arrivals), so this stays a converge-on-conflict cleanup — catch the violation and re-read the line the winner created, as OpenCart::for() already does.

## Done

- [x] ADR-0048 (mail-and-receipts Task 2): a paid order gets its Rechnung
      the moment it is paid, and it says so. `IssueInvoiceOnOrderPaid`
      listens to `OrderPaid` (registered in `registerListeners()`) and
      calls the existing `IssueInvoice`, now idempotent per `order_id`
      (`Invoice::withoutBrandScope()->where('order_id', …)->first()`
      before ever opening a transaction) and accepting an optional
      `PaymentSnapshotData` — `due_on` collapses to `issued_on` and
      `paid_at`/`paid_via`/`payment_reference` are written at the same
      moment as everything else, never as a later edit. Before this, only
      an invoice-settled registration order (issued at placement by
      `PlaceOrderFromSettlementRequest`) produced any document at all — a
      card-paid registration and every cart order (ADR-0047) got nothing.
      `orders.billing_party` (new nullable JSON column, `BillingPartyData`
      cast) closes the gap that made that impossible: settlement requests
      already carried a billing party but only the `invoice` branch kept
      it, and a cart checkout gathered none — `PlaceOrderFromCart` now
      takes a `BillingPartyData` parameter, `CartController::checkout()`
      builds one from the signed-in payer's account (`BillingPartyData::
      ofAccount()`, name and email only, country left null rather than
      guessed — the cart's own tax calculation already resolves ADR-0030's
      domestic treatment with no billing country at all, so a fabricated
      one would change nothing but the printed page).
      `Settlement\ProviderLabels::labelFor()` turns a raw provider key
      into the human label a payer actually reads (`stripe → 'Stripe'`,
      `manual → 'Überweisung / manuell'`, …); `InvoiceDocumentRenderer`
      prints "Bezahlt am … via …" instead of "Zahlbar bis …" on the PDF
      half and, on the Factur-X half, adds the UNTDID 4461 payment means
      (SEPA credit transfer for `manual`, bank card for
      `stripe`/`mollie`/`unzer`, matched off the *label* string since
      that's what the invoice itself carries) and sets
      `setDocumentSummation()`'s `totalPrepaidAmount` to the gross total
      with `duePayableAmount` zero — read back and asserted with
      `ZugferdDocumentPdfReader::readAndGuessFromContent()` in
      `PaidInvoiceTest`. The invoice mail (`commerce.invoice-issued`, one
      template key, both wordings — `TemplateRenderer` runs the subject
      through `Blade::render()` exactly like the body, so `@if($paid)`
      works in a subject string too) branches to thank the payer for the
      payment instead of naming a due date; `/my/orders`
      (`MyOrderData.invoicePaid`) labels the document button "Rechnung
      (bezahlt)" instead of "Rechnung". `PaidInvoiceTest.php` covers
      one-invoice-per-order under replay (both by calling
      `RecordPaymentSuccess` twice — the existing not-pending guard
      already stops a second `OrderPaid` — and by replaying `OrderPaid`
      itself straight into the listener, to prove `IssueInvoice`'s own
      guard holds independently), an invoice-settled order paid later
      staying at one untouched invoice, the missing-billing-party warning
      path (`Log::spy()`), the archived XML/PDF, and the `/my/orders`
      projection; `CartCheckoutTest` gained the real checkout → webhook-
      success → invoice-with-the-cart's-billing-party path.
- [x] ADR-0047 point 6, Task 14: the infrastructure flip and the rush
      itself. `SESSION_DRIVER`/`CACHE_STORE` are `redis` in `.env` and
      `.env.example` (the queue already was); a database-backed session
      is the first thing to fall over under a rush, and this is
      load-tested rather than assumed. `database/seeders/Demo/DemoRushSeeder.php`
      (`konventix:seed --rush`, additive, skips the interactive archetype
      prompts on its own) seeds a "Rush" brand on `rush.localhost`: one
      event, one `instant`/`on_payment` capacity-300 ticket ("Rush
      ticket"), one 200-unit shop item ("Rush shirt"), `commerce:shop` +
      `commerce:waiting-room` on, `admit_per_interval` 100, and 1,200
      login-ready members (`rush+{n}@konventix.test` / `password`).
      Checkout needed a PSP-less path to "start" at all:
      `Settlement\LocalHostedCheckoutAdapter` implements
      `ProvidesHostedCheckout` for the `null` provider, bound only in
      `local`/`testing` (`CommerceServiceProvider`) — `NullSettlementAdapter`
      stays the honest "no PSP, no hosted checkout" adapter everywhere
      real. `tests/Load/ticket-rush.js` drives 1,000 arrivals, capped at
      40 concurrent VUs × 25 sequential arrivals (`VUS`/`ARRIVALS_PER_VU`;
      `VUS=1000 ARRIVALS_PER_VU=1` reproduces the all-at-once profile),
      through login, the waiting room, registration, the shop and checkout
      against `laravel.test` with `Host: rush.localhost`, redirects
      followed by hand (several of this app's own are absolute URLs a
      k6 container cannot resolve), and a per-arrival synthetic
      `X-Forwarded-For` — every VU otherwise shares this container's one
      IP, and `IssueWaitingRoomTicket`'s per-IP cap (five new tickets)
      refused nearly all of them until that header was added, a real bug
      the first three iterations missed and mis-attributed to raw
      concurrency. Measured 2026-08-27: 754 of 1,000 arrivals completed
      inside the run window (742 admitted at 100 per 5 s), 0 server
      errors, quota consumed exactly 300/300, stock consumed exactly
      200/200 — no seat and no shirt oversold, the load-test's actual
      job. `http_req_failed` measured 5.9%; `tests/Load/RushResultTest.php`
      (`--group=load`) asserts integrity strictly and applies the 1% bar
      only to the full 1,000-VU profile, printing the measured rate
      otherwise — a genuine concurrency ceiling of this dev container under ~40
      simultaneously open connections (isolated by direct measurement:
      40 concurrent sustains 500+ req/s at p95 ≈ 45 ms, a sudden 200
      collapses to ~22 req/s), not a data-integrity gap. Left for a later
      infrastructure task, not re-litigated here.
- [x] ADR-0047 point 5, Task 13: the holding page counts down, the tick
      admits, and the console can see the queue breathe.
      `WaitingRoomController` (`show`/`status`) behind
      `ensure-feature:commerce:waiting-room` only — not
      `ensure-waiting-room` (the page IS the waiting room), not `auth`
      (a guest queues like anyone else). `GET /waiting-room`
      (`commerce.waiting-room.show`) renders `waiting-room/Show.vue`
      (position, ETA, `aria-live="polite"`, a `<noscript>` meta-refresh
      fallback, a five-second poll of `status` copied from
      `NotificationBell.vue`'s `fetchJson`, `window.location.assign()` on
      `admitted`); a visitor with no ticket cookie is sent to `/shop`, one
      already admitted who revisits is sent straight to its
      `intendedUrl`. `GET /waiting-room/status`
      (`commerce.waiting-room.status`) answers
      `{position, admitted, redirectTo, depth}`. `CommerceWaitingRoomGate`
      now redirects to the named route instead of a literal path.
      `AdmitWaitingRoomBatch` (`src/Jobs/`, queue `waiting-room`,
      `ShouldBeUnique`/`uniqueFor` 5s) admits a batch, writes
      `waiting-room:{brand}:last_admission` (new
      `WaitingRoomStore::recordAdmissionRun()`/`lastAdmissionAt()`, both
      implementations) whether or not anyone was admitted, and records
      `Pulse::record('waiting_room_admitted', ...)->sum()` when someone
      was. Its `uniqueId()` folds in the job's own delay rather than the
      brand id alone — a literal brand-only key would let a tick's unique
      lock swallow eleven of its own twelve staggered dispatches, since
      Laravel checks that lock at dispatch time, before any `->delay()`
      has elapsed (verified against this app's Laravel version before
      writing it this way; see the class's docblock). `commerce:waiting-room-tick`
      (`src/Console/WaitingRoomTick.php`, scheduled
      `everyMinute()->withoutOverlapping()` beside the cart sweep) walks
      every brand — no `BrandContext`, there is none outside a request —
      and dispatches twelve staggered batches for each with the feature
      on and a non-empty queue. `config/horizon.php` gained a
      `waiting-room` supervisor (`maxProcesses` 2 in `local`/`production`),
      separate from `supervisor-1`. `WaitingRoomCheck`
      (`src/Health/`, a `Spatie\Health\Checks\Check`) fails when a
      feature-on brand's queue is non-empty and no admission ran in the
      last two minutes; registered in
      `App\Providers\ObservabilityServiceProvider::registerHealthChecks()`
      beside `HorizonCheck` — confirmed first that `App` importing
      `Konventix\Commerce` does not trip
      `tests/Unit/ArchitectureTest.php`'s "App not to use Konventix" arch
      rule (Task 11 precedent; the provider already imports
      `Konventix\Identity` and `Konventix\Platform`). No Pulse dashboard
      card: the documented custom-card pattern needs a Livewire
      component, its view, a Livewire registration, and a
      publish-and-edit of `dashboard.blade.php` (never published in this
      app) — past the "one class, one view" bar this task set. The
      recording ships; the card is the backlog line below.
- [x] ADR-0047 point 5, Task 12: a door for the rush. `src/WaitingRoom/`
      — `WaitingRoomStore` (`RedisWaitingRoomStore` on a sorted set per
      brand plus a hash per ticket, `ZPOPMIN` for atomic admission;
      `ArrayWaitingRoomStore` the same shapes in PHP arrays, bound only
      for `WAITING_ROOM_STORE=array` — test-only, singleton, holds no
      request-scoped state), `TicketCookie` (`waiting_room_{brandId}`,
      `id.hash_hmac(...)`, constant-time `parse()`), `IssueWaitingRoomTicket`
      (reuses a cookie's or a signed-in user's still-known ticket before
      minting a new one; the per-IP cap — five new tickets/hour, hashed —
      is charged only on a genuinely new ticket), `AdmitFromWaitingRoom`
      (`Cache::lock` around pop-then-stamp, TTL from
      `WaitingRoomSettings::forBrand()`), `CommerceWaitingRoomGate`
      (Platform's `WaitingRoomGate` contract: feature off → pass; an
      operator holding `commerce.manage-products` on the brand → pass; an
      admitted ticket → pass; else issue/re-read + cookie + redirect to
      `/waiting-room` — a plain path, `TODO(tranche-3 T13)` marks where the
      named route replaces it once the holding page exists — done in
      Task 13, see above).
      `WaitingRoomSettings` (`admit_per_interval` default 50,
      `admission_ttl_minutes` default 30, `forBrand()` the
      `BookkeepingSettings::forOperator()` idiom) with its own small form
      — `PUT /admin/brands/{brand}/waiting-room`
      (`WaitingRoomSettingsAdminController`, `commerce.manage-products`,
      the same capability the gate's operator bypass checks) — deliberately
      NOT folded into `UpdateBrandFeatures`.
      The brand edit page's Waiting room card reaches the brand admin
      host through `BrandFeature`'s new `settingsComponent`/`settingsProps`
      (Platform), not a `SlotRegistry` slot: `SlotRegistry`'s feature gate
      reads the *ambient* `BrandContext::current()`, which the admin host
      never resolves (ADR-0029) — this extension point instead receives
      the route-bound `Brand` explicitly, the same one
      `BrandAdminController::edit()` already computes each feature's
      Pennant state against.
      `config/commerce.php` (new) names the store and the `waiting-room`
      Redis connection (`config/database.php`, `REDIS_WAITING_ROOM_DB`
      default 2 — its own database so a cache flush never drops an active
      queue). The alias itself — `ensure-waiting-room` — and the
      `WaitingRoomGate` contract are Platform's
      (`app-modules/platform/src/WaitingRoom/`,
      `Http/Middleware/EnsureWaitingRoomAdmission`): a module standing
      behind the door names only the alias string, never Commerce.
      Applied to `/shop*`/`/cart*` (Commerce's own routes) and
      `registration.show`/`registration.store` beside `require-consent`
      (Registration — imports nothing from Commerce). Necessary cookie
      declared (`waiting_room_*`, "Your place in the queue while a sale is
      busy", 30 minutes).
      `tests/WaitingRoomTest.php`; Registration's own
      `tests/WaitingRoomOnRegistrationTest.php`; Platform's
      `tests/WaitingRoomAliasTest.php` proves the alias degrades to a
      pass-through with nothing bound. The holding page and the scheduled
      admission tick are Task 13.
- [x] Slice 24c prerequisite (ADR-0047, Task 11), one checkout settles a
      ticket and a T-shirt — `PlaceOrderFromCart::handle()` turns a cart
      into one `Order` (`settles_type = 'cart'`, `settles_id`/`request_key`
      the cart's own id), one `OrderLine` per cart line carrying
      `settles_kind`/`settles_ref` straight from the line's own
      `kind`/`ref_id`, moves the cart to `checked_out` and starts the
      chosen account's hosted checkout — the same account-offered guard
      `CheckoutController::start()` already applies, now duplicated inside
      the Action itself so a bad account never leaves a stray order
      behind: it is checked *before* the order exists, not after. Reusing
      a cart already `checked_out` with a still-pending order is the
      idempotent path a double-submit or a retried checkout needs — the
      controller's own cart lookup for `/cart/checkout` widens to include
      `checked_out` alongside `open` for exactly that reason, while every
      other cart route keeps the narrower `Cart::open()` lookup
      (`{line}` resolved through the payer's own open cart, never a bare
      `CartLine::find()`).
      `RecordPaymentSuccess::settleOrder()` now also emits one
      `OrderLineSettled` per line, **only for a `settles_type: 'cart'`
      order** — a direct registration order still has exactly one thing to
      confirm and `OrderPaid` already says which, so emitting the new
      event for it too would let Registration confirm the same
      registration twice from two different events. Registration's new
      `RecordPaymentOnOrderLineSettled` is the only thing that reaches a
      cart order's registration lines; `RecordPaymentOnOrderPaid` already
      ignored `settles_type: 'cart'` before this task, since it only ever
      acted on `'registration'` — no code change there, just a test that
      says so explicitly. `RefundOrder`'s ADR-0037 check-in guard now
      looks through a cart order's `settles_kind: 'registration'` lines
      the same way it already read a direct order's `settles_id` — one
      checked-in admission among several still blocks the whole order,
      since a cart refund stays whole-order.
      `CartController` (`GET /cart`, `POST /cart/lines`, `PATCH
      /cart/lines/{line}`, `DELETE /cart/lines/{line}`, `POST
      /cart/checkout`, all behind `ensure-feature:commerce:shop` + `auth`)
      and `cart/Show.vue` (line list with quantity steppers and remove, a
      `TaxCalculator` preview of the totals checkout recomputes for real,
      one button per offered account like `pay/Show.vue`, an "expires in N
      minutes" note, an empty state linking to `/shop` — no dirty guard,
      every change here is its own immediate request). `shop/Show.vue`'s
      buy button, disabled since Task 8, now posts for real. Nav entry
      "Cart" at order 31 beside "Shop", badged from a new shared `cart`
      Inertia prop (`HandleInertiaRequests` → `Cart\CartSummary`, a plain
      service the host layer resolves by name — it may `use` a Commerce
      class the same way it already does Platform's own registries,
      confirmed against `tests/Unit/ArchitectureTest.php`'s dependency
      check, which only flags a real type-hinted dependency, never a
      service resolved through `app()`; no Platform indirection needed).
      `commerce:expire-carts` gained `->withoutOverlapping()` (Task 9
      review minor).
      `MyOrderData` gained a `lines[]` breakdown (`label`, `quantity`,
      `lineGross`, `kind`, `registrationUrl`) so "my orders" can say what a
      cart order actually contained — the last field resolves
      `registration.mine.show` server-side for a `registration`-kind line,
      the same way `payUrl`/`documentUrl` already resolve their own
      routes. `CommerceGdprHandler` now exports the subject's carts
      alongside their orders, and genuinely deletes an *open* one on
      erasure — releasing a product line's stock and emitting
      `CartLineRemoved` for a registration line exactly the way
      `RemoveCartLine` would, since an open cart holds a reservation, not
      a commercial fact the way a placed order does; a `checked_out` or
      `expired` cart is left alone.

- [x] Slice 24b (ADR-0047, Task 10), a registration joins the cart —
      `CreateOrderOnSettlementRequested` now branches on `RegistrationSettlementRequested`
      instead of always placing an order: an `instant` settlement request on a
      brand with `commerce:shop` active goes to the new
      `PlaceRegistrationLineInCart` (`OpenCart::for()` the registrant's own
      cart, one `registration`-kind line per settlement request — idempotent
      on `(cart, kind, request_key)` via `cart_lines_request_key_unique`,
      `quantity` fixed at 1 with `unit_amount_net` the sum of every request
      line's own net, `label`/`tax_class` from the first line — a
      registration always settles as one line). `invoice` settlements, and
      every settlement on a brand with the feature off, still get the
      direct order this listener always produced;
      `PlaceOrderFromSettlementRequest`'s lines now carry
      `settles_kind => 'registration'`/`settles_ref` (Task 9's columns) so a
      future cart checkout's order lines can tell what each one paid for.
      The feature check resolves `Brand::query()->find($event->brandId)` —
      Platform's scoping primitive, the one cross-module model this listener
      is allowed to touch — purely to ask Pennant whether the flag is on.

- [x] Slice 24b (ADR-0047, Task 9), the cart and its reservation — a cart
      holds what a person intends to buy, and holds the stock with it for
      twenty minutes. `Cart` (`payer_user_id`, `status`, `expires_at`,
      `checked_out_order_id`) and `CartLine` (`kind`: `product` or
      `registration`, `ref_id`, `request_key`, a settlement-time snapshot
      of `label`/`unit_amount_net`/`tax_class`, exactly like `OrderLine`'s
      but taken one step earlier). `OpenCart::for(brandId, payerUserId)`
      finds a payer's open cart or opens one, `brand_id` explicit rather
      than from ambient context — a queue or admin caller has none — with
      the partial unique index `carts_one_open_per_payer` closing the same
      concurrent-open race `entrants_one_live_per_team` already closes
      elsewhere: the insert is attempted and a lost race re-reads the cart
      the other request created rather than erroring.
      `AddProductToCart` locks the variant, refuses with
      `StockExhaustedException` when `hasStockFor()` says no, consumes,
      merges a second add of the same variant into its existing line
      rather than duplicating it, and emits `StockExhausted` the moment
      the variant hits zero. `ChangeCartLineQuantity` consumes or releases
      the delta between the old and new quantity under the same lock, and
      refuses a registration line with a `LogicException` — its quantity
      is fixed by the registration itself, not by the cart.
      `RemoveCartLine` releases a product line's stock silently; a
      registration line has none to release and instead emits
      `CartLineRemoved`, which is what tells Registration (Task 10) to
      cancel the still-pending registration the line was holding
      (ADR-0047 point 3). Every one of these calls `Cart::touchExpiry()`,
      so continued activity keeps the twenty-minute reservation alive.
      **The sweep** (`commerce:expire-carts`, scheduled every fifteen
      minutes) mirrors `Registration\Actions\ExpireWaitlistOffers`
      exactly: `withoutBrandScope()` across every brand, one transaction
      per cart so one bad row never blocks the rest, a product line's
      stock released and a registration line's `CartLineExpired` emitted
      before the cart itself moves to `expired`.
      `GuardsProductVariantReferences::isReferenced()` — a documented stub
      returning `false` since Task 7, because neither `cart_lines` nor the
      settlement columns on `order_lines` existed yet — now has real
      teeth: a variant referenced by an *open* cart's line or a settled
      order line refuses `SaveProduct`'s variant removal and
      `DeleteProduct` alike; an expired cart's line does not count, since
      the sweep already released its stock. `order_lines` gained
      `settles_kind`/`settles_ref`, the per-line counterpart of `Order`'s
      own `settles_type`/`settles_id` — Task 11 is what actually writes
      them, once a cart checkout can produce an order whose lines settle a
      mix of products and registrations.

- [x] Slice 24a (ADR-0047), the public shop — a brand's catalogue gets a
      storefront: `/shop` lists every published product and `/shop/{slug}`
      shows one, both behind the per-brand `commerce:shop` feature Task 5
      introduced (`ensure-feature:commerce:shop` — a brand that never
      switched it on 404s both routes, the middleware's own default). Brand
      scoping is the ordinary `BelongsToBrand` global scope, not an admin-
      host escape, because `ShopController` runs on the brand's own domain.
      `ShopProductData`/`ShopVariantData` are the one shared read shape
      between the listing and the product page — the listing simply renders
      less of it than the show page does — with `imageUrl` resolved through
      `MediaLibrary::urlFor()` before either reaches the Data object, since
      Commerce may not import Platform's `MediaItem` (`ArchitectureTest`).
      `ShopProductData::ofModel()` sets each loaded variant's `product`
      relation back to the product handed in, because
      `ProductVariant::effectiveUnitAmountNet()` reads it for a variant with
      no price override of its own — the common case — and strict mode
      (`Model::shouldBeStrict()`) refuses the lazy load otherwise; a caller
      that only eager-loads `variants`, never `variants.product`, would 500
      the moment a real, un-overridden variant reached the page.
      `Product::isSoldOut()` badges the index and disables the whole variant
      `<select>` on the product page; an individual sold-out variant among
      others disables just its own `<option>`. The add-to-cart form is
      shipped without a destination: the variant select and quantity input
      are real, but the buy button renders `disabled` with
      `title="Cart arrives with a later task"` until Task 11 wires
      `commerce.cart.add` — a guest gets a "Sign in to buy" link to `/login`
      in the button's place instead. Nav entry "Shop" at order 30
      (`AREA_BRAND`, icon `shopping-bag`, `feature: 'commerce:shop'`).
      Commerce's own contribution to Event's `event.landing` slot,
      `commerce/ShopLandingCard`, follows Registration's `EventOffers`
      precedent exactly: server-resolved props so the call to action is in
      the first paint, `feature: 'commerce:shop'` on the slot registration
      itself rather than a check inside the closure, and nothing rendered
      when the brand has no published product for that event.
      `DemoCommerceSeeder::seedShop()` activates the feature and seeds two
      products with variants — one in stock, one sold out via a
      zero-capacity implicit variant — for the LAN archetype only, through
      the real `SaveProduct` action rather than factory rows; a trade fair
      gets no shop, the same on/off demonstration Competition's module gate
      already made for a different archetype split. `DryRunTest` gained a
      `commerce.shop.show` slug resolver and a small
      `dryRunIsReachableForBrand()` filter — the first public route in the
      suite that is legitimately unreachable for one archetype and not the
      other by design, rather than by a fixture the seed forgot to fill in.

- [x] Slice 24 (ADR-0047), the console — a brand's shop catalogue gets its
      admin at `/admin/products` (`ProductAdminController`, index/store/edit/
      update/destroy), gated on the two capabilities Task 6 added
      (`commerce.view-products`/`commerce.manage-products`). One Data class,
      `SaveProductData`, serves both create and update through
      `SaveProduct::handle()`: it creates or updates the product and syncs
      its variants by id — a submitted row with a known id updates that row,
      one with none (or one this product does not own) inserts, and an
      existing row missing from the submission is deleted unless a settled
      order still references it. That guard,
      `GuardsProductVariantReferences::isReferenced()`, answers false today
      by design — `cart_lines` and the settlement columns on `order_lines`
      are Task 9's, and its docblock names Task 9 as the one that fills it
      in and writes the "refused while referenced" test. Capacity may never
      drop below stock already consumed (422 on `variants.{i}.stockCapacity`).
      An empty variant list still gets exactly one row: the implicit variant
      `Product`'s own docblock promised, capacity from the form's top-level
      `stockCapacity` field. `DeleteProduct::handle()` carries the same
      referenced-variant guard and releases the product's image usage;
      variant rows themselves cascade off the FK.
      Image references go through `MediaLibrary::recordUsage()`/
      `releaseUsage()` like every other picker (ADR-0035). Two new
      `MediaLibrary` methods, `belongsToBrand()` and `pickableImages()`, are
      what let this module check ownership and list a brand's images for
      `MediaPicker.vue` without importing Platform's `MediaItem` model
      directly — a straight copy of `StaticPageAdminController::edit()`'s
      inline query would have crossed the boundary
      `tests/Unit/ArchitectureTest.php` enforces, since `MediaItem` is not on
      that test's short list of Platform primitives a module may import.
      The editor (`Edit.vue`) carries name/slug/description, an event
      `<select>` fed by `ConsoleEventSources`, `MediaPicker`, tax class and
      price display, a publish checkbox, and a variants repeater built on
      `useDragReorder` with keyboard parity on the reorder handle — a row's
      `position` is finalized from the array's own order at submit time
      rather than trusted from whatever the client last carried. Creation
      resolves its brand from the console's own selection rather than a
      field on the form — `SaveProductData` carries no `brandId` — so "New
      product" on the index is disabled until an operator has picked one.
      Nav entry "Products" at order 24 (icon `shopping-bag`, added to
      `navIcons.ts`). `tests/Feature/DryRunTest.php` gained a `product`
      parameter resolver for when Task 14's demo seed produces one; until
      then `admin.products.edit` is a named skip in that sweep, same as
      every other route a seed doesn't yet fill.

- [x] Slice 23 (ADR-0046) — a brand may hold several live settlement
      accounts at once, one per provider, and the payer chooses which
      settles their order. `SettlementAccount::liveAccountsFor()`,
      `isOffered()` and `checkoutLabel()` are the seam the checkout page
      offers accounts through; an account is offered only once it's live,
      connected, and — for Stripe — its `card_payments` capability reads
      `active`, closing the go-live readiness gap this TODO used to carry
      as an open item. `RefreshSettlementAccountCapabilities` keeps that
      capability current on return from onboarding and on a daily sweep.
      The settlement-account console is a list per brand now, not "the
      account": every live account with its offered state, an inline form
      to give it its own `checkout_label`, and the connect form limited to
      whichever providers aren't already live. Settlement events carry the
      account id alongside the provider. The orders console and the
      bookkeeping export both name the provider — the latest succeeded
      payment on the order — on the orders list, the order detail, the
      readable journal's `Zahlungsanbieter` column and the DATEV batch's
      `Beleginfo – Art 1`/`Inhalt 1` pair.

- [x] `CommerceGdprHandler` in `src/Gdpr/`, reconciled with statutory
      invoice retention (§147 AO). Export reads `orders.payer_user_id`
      (ADR-0044) and returns every order the subject owes on the brand,
      with its lines, invoices, payments and credit notes; deletion is a
      deliberate no-op in both modes, because an issued invoice may not be
      erased and the payer link must stay resolvable for a later audit.

- [x] ADR-0044 — the payer is on the order. `orders.payer_user_id` names
      who owes it, a plain ULID with no foreign key, set by whoever creates
      the root — for now that's Registration, through a widened
      `RegistrationSettlementRequested::$registrantUserId`; the idempotent
      path fills a null payer on replay so a request that only later learns
      who's paying still converges. No backfill: no order in Konventix
      predates this decision, so there's nothing to fill in for existing
      rows.

- [x] The orders list defaults its brand filter from the console selection,
      cleared per list with `brand=all` — no event filter, because an order
      settles a registration and the list carries no event column.

- [x] The pay link and the invoice reach the person who owes for them.
      `/pay/registration/{ulid}` and the archived PDF both worked and
      neither had an inbound route in the UI. Commerce contributes them to
      Registration's own account page through the `registration.mine.show`
      slot, which is the only direction the boundary allows: an order is
      keyed by `settles_type`/`settles_id`, so this module cannot resolve
      "whose orders are these" without reading Registration's tables
      (ADR-0004) — but asked about one registration whose ownership the
      caller has already checked, it can answer completely. The PDF
      download is a temporary signed link minted during that same render,
      because the download is a separate request Commerce could not
      authorize on its own.

- [ ] The confirmation mail still does not carry the pay link. The account
      page does now (see Done), but a payer who never comes back to the site
      has nothing to click — and `instant` settlement is exactly the case
      where they might not.

- [x] The standalone "my orders/invoices" list, now that the payer read
      model exists (ADR-0044). `GET /my/orders` (`commerce.mine.index`)
      lists every order `payer_user_id` names on this brand, each with its
      pay link and, once one is archived, a temporary signed link to its
      invoice PDF — `MyOrderData::ofOrder()` mints both the same way the
      `registration.mine.show` slot does. Reached from a new "My orders"
      account-menu item; no `permission`, because ownership is what
      authorizes it, not a capability anybody can be granted.

- [x] Slice 7c — bookkeeping export (ADR-0040). Interview decisions: both
      formats (DATEV Buchungsstapel + readable CSV journal), SKR03
      Automatikkonten defaults (8400/8300/8336/8195 — BU-Schlüssel stays
      empty by construction), documents only, one collective debtor.
      `CollectDocumentJournal` gathers one operator-month across all the
      operator's brands (D-COM-05) and splits each document into tax
      buckets — a mixed-rate invoice books one row per revenue account,
      summing exactly to the document; a partial credit note pro-rates the
      invoice's own split with the last bucket absorbing the rounding
      remainder. `DatevBuchungsstapelWriter` emits the canonical EXTF
      layout (version 700/13, all 125 columns by name, CP1252, CRLF,
      festgeschrieben), reverse-charge rows carrying the buyer's VAT ID in
      `EU-Land u. USt-IdNr.`; `JournalCsvWriter` the readable companion.
      Per-operator `BookkeepingSettings` (consultant/client numbers,
      accounts) with a settings form and month-picker downloads at
      `/admin/bookkeeping`, gated on `commerce.export-bookkeeping`.

- [x] Slice 7b — payment providers (ADR-0039; deps user-approved:
      `stripe/stripe-php`, `mollie/mollie-api-php`, `unzerdev/php-sdk`).
      **The integration model** (ADR-0039): hosted checkout only (SAQ A),
      settlement truth exclusively from verified webhooks — Stripe by
      payload signature, Mollie and Unzer by fetch-back with the brand's own
      key, so a forged call can at worst re-read real state. `Payment` is a
      first-class record, unique per `(provider, external_id)` so replays
      converge on one `OrderPaid`; `PaymentReceived`/`PaymentFailed`/
      `SettlementAccountConnected` moved from proposed to implemented.
      **Adapters** behind per-provider SDK seams (`StripeApi`, `MollieApi`,
      `UnzerApi` — tests run against fakes, no SDK call leaves the process):
      Stripe as direct charges on the organizer's connected account (v2
      accounts, full dashboard, no `application_fee_amount` ever), Mollie
      and Unzer against per-brand keys encrypted on `SettlementAccount`.
      **Refunds** gained their PSP side: `SettlementAdapter::refund()`,
      routed through the provider that took the payment (not the brand's
      current account), called first inside `RefundOrder`'s transaction so
      a provider refusal aborts the paperwork.
      **Public checkout** for `instant` settlement at
      `/pay/registration/{ulid}` (knowing the unguessable ULID authorizes
      viewing lines and totals; billing data never renders) with a
      display-only return state — the page never decides payment truth.
      **Connect console** at `/admin/settlement-accounts`
      (`commerce.manage-settlement-account`): Stripe walks hosted
      onboarding with the row unconnected until return; Mollie/Unzer take
      the brand's key in one step; Unzer registers this deployment's
      webhook on the account, because Unzer notifies per account.
      `BookManualPayment` now resolves the brand's live *manual* account id
      and never references a PSP account for a hand-booked transfer.

- [x] Slice 7a — internal money flows (no new dependencies).
      **ADR-0037's refund block**: `CheckedInAdmission`, Commerce's own read
      model fed by `AdmissionCheckedIn` (Slice 5a shipped it carrying
      `registration_id` for exactly this), and the guard in `RefundOrder`.
      D-COM-15 forbids querying Admission's tables, so this projection is the
      only lawful path. Keyed per admission, not a counter, so it converges
      under the replay an offline device makes normal (ADR-0033); it keeps the
      *earliest* check-in and ignores check-outs.
      **Credit notes** (ADR-0031, ADR-0036) with their own gapless series
      `GS-PREFIX-YYYY-NNNNN`, separate from invoices so neither counter can
      perturb the other and a tax advisor can tell the documents apart by
      number alone. A credit note requires an invoice — an `instant` order
      paid at checkout has no document to correct and no party to address, so
      refunding it moves money without paperwork.
      **`SettlementAccount` + `SettlementAdapterRegistry`** (ADR-0007): the
      seam the contract always implied but nothing provided. A brand with no
      connected account resolves to `manual`, which is the honest default and
      the only mode the definition of done requires. One live account per
      brand, enforced by a partial unique index.
      **Dunning**: `commerce:dunning`, scheduled daily via the new
      `ModuleServiceProvider::registerSchedule()` hook. `InvoiceOverdue` once
      per invoice, `DunningLevelRaised` per escalation at 7/21/42 days. No
      mail is sent from Commerce — Messaging owns what a level means, and a
      test asserts the Action never references it.

- [x] Write `SPEC.md` — purpose, ownership boundary, event contract.
- [x] Consume `RegistrationSettlementRequested`
      (`Listeners\CreateOrderOnSettlementRequested` →
      `Actions\PlaceOrderFromSettlementRequest`, scalar-only, idempotent per
      settled thing): order + `OrderLine` rows with the full settlement-time
      snapshot (declared class, resolved rate, computed amounts — ADR-0017),
      `OrderPlaced`, and for `settlement: invoice` an `Invoice` via
      `Actions\IssueInvoice` + `InvoiceIssued`.
      `tests/RegistrationImportBoundaryTest.php` now exempts exactly the
      `Registration\Events\` namespace (the Admission pattern) — the
      Phase 1 "no import at all" rule was correct only while Commerce was
      dormant by construction.
- [x] German VAT treatment per ADR-0030 (`Tax\GermanVat` +
      `Tax\TaxCalculator`, bcmath throughout, per-line half-up rounding so
      totals equal the sum of printed lines): standard 19% / reduced 7%
      resolved from the declared class at settlement time; reverse charge
      for a billing party holding a VAT ID in another EU member state
      (unvalidated — see Backlog); §19 UStG election read from the operator
      through Platform's `OperatorSettings` doorway (never the Operator
      model). Currency fixed per brand (`brands.currency`).
      `tests/TaxCalculatorTest.php` covers the matrix.
- [x] `Invoice` + per-brand gapless number ranges: `InvoiceNumberRange` one
      row per (brand, year), consumed under `lockForUpdate` inside the
      issuing transaction (`Actions\DrawInvoiceNumber`) — format
      `PREFIX-YYYY-NNNNN`, counter resets yearly, prefix defaults from the
      brand slug (Phase 2 interview decision; draw-on-demand replaced the
      originally sketched seed-on-`BrandCreated`, which could still leave
      gaps for pre-existing brands). Due date from the payload's
      `terms_days`; billing party and operator seller identity snapshotted
      onto the row as typed Data casts (ADR-0009) so the document re-renders
      identically forever (ADR-0031).
- [x] Hybrid ZUGFeRD rendering + GoBD archival per ADR-0031
      (`Documents\InvoiceDocumentRenderer` + `Jobs\GenerateInvoiceDocument`,
      dispatched afterCommit): Blade template → dompdf for the visual half,
      horstoeko/zugferd Factur-X (EN 16931) XML built from the same
      order-line snapshot, merged to PDF/A-3. Written exactly once to the
      dedicated `invoices` disk (brand/year-sharded, sha256-sealed on the
      row); replays never rewrite the archive. §19 and reverse-charge
      template variants carry their statutory notes.
      `tests/InvoiceDocumentTest.php` renders for real.
- [x] First-class manual payment booking with UI/authorization
      (`Actions\BookManualPayment` → `ManualSettlementAdapter`, capability
      `commerce.book-manual-payment` distinct from viewing): operator-console
      orders index (`OrderAdminController`, DataTable pattern, search by
      invoice number/registration ULID) with invoice column, sealed-PDF
      download (`InvoiceDocumentController` — always the archive copy, never
      a re-render), and a booking dialog. Navigation entry registered
      (icon `receipt`). `tests/OrderAdminTest.php`, `tests/SettlementChainTest.php`.
- [x] Decided the zero-value order question — see ADR-0026 and the settled
      note in `SPEC.md` "Owns" — `Order`: `settlement: free` creates no
      Commerce record at all, ever.
- [x] Model `Order` — ULID, `BelongsToBrand`, `settles_type`/`settles_id` as a
      plain ULID reference (never an Eloquent relation to Registration,
      ADR-0006), `status` (`OrderStatus`: pending/paid/cancelled), `currency`,
      `total_net`/`total_gross`, `paid_at`.
- [x] Settlement adapter contract (`Konventix\Commerce\Settlement\SettlementAdapter`),
      account-scoped on every call (ADR-0007) — `$accountId` is the first
      argument of `markOrderPaid()`. `NullSettlementAdapter` (deliberate
      no-op, no connected account) and `ManualSettlementAdapter` (first-class
      manual payment booking, marks paid + emits `OrderPaid`) as the two
      Phase 1 implementations.
- [x] `OrderPaid` event with the SPEC.md scalar payload, emitted by
      `ManualSettlementAdapter`; `tests/RegistrationImportBoundaryTest.php`
      asserts Commerce never imports a single `Konventix\Registration` class.
- [x] Capability catalog in `src/Enums/Permission.php`, `BookManualPayment`
      kept separate from `ManageSettlementAccount`.

<!-- Move completed items here with the PR that finished them. -->
