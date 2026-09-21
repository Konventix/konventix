# Commerce — TODO

> Living document. Update it in the same PR that changes the code: check off
> what you completed, add follow-ups you discovered. Do not generate status
> dashboards, percentages, or summary tables here.

## Next (Phase 1 PoC — no payment; keep Commerce dormant and prove the boundary)

- [x] The checkout's contract-consent gate is a route alias now (round 4,
      Task 33, ADR-0066; D-CPL-13/14). The six routes that conclude a
      purchase — `cart.choose`/`cart.confirm`/`cart.checkout` and the two
      pay triples — carry Compliance's `require-contract-consent` with the
      confirmation step named as the page to come back to, so agreeing lands
      the payer back where they were and no POST is ever replayed. The
      controllers' own `consentRedirect()` stays: it is what still guards
      the ungated step-one pages (`/cart`, `/pay/registration/{id}`,
      `/pay/order/{id}`), which are a basket and an offer rather than a
      contract.
- [x] The two-step checkout and the opaque binding bag (round 4, Task 32,
      ADR-0066; D-CPL-06/07). `binding_context` json on `order_lines` **and**
      `cart_lines` — no backfill, no index, and no branch in this module
      reads a key of it: `RegistrationSettlementRequested` carries the bag,
      `PlaceOrderFromSettlementRequest` and `PlaceRegistrationLineInCart`
      store it, `PlaceOrderFromCart` copies it on, and
      `CheckoutConsentSlots::unionOfBags()` hands the union to the consent
      slot with the chosen provider and the order id beside it. The checkout
      itself split into *choose* and *confirm* on all three paths
      (`Http/Controllers/Concerns/ChoosesSettlementAccount`,
      `pay/Confirm.vue`, `cart/Confirm.vue`), with the choice held in the
      session per checkout target and cleared on the way back and on the way
      out. `tests/CheckoutBindingContextTest.php`, and
      `CheckoutContractConsentGateTest` extended with the two-step cases.
      **Known narrowing:** `unionOfBags()` is single-valued on `event_id`, so
      a cart spanning two events resolves the first event's bindings only —
      widening it is a change to Compliance's `BindingContext`, not to this
      method.

- [x] A registration cart line carries the registration's own quantity
      (bugfix round 2026-09-21, Task 19): `PlaceRegistrationLineInCart`
      takes it from the settlement request's lines — where they agree on
      one — and keeps `unit_amount_net` per unit, so the cart and the
      checkout multiply it like any product line. `ChangeCartLineQuantity`
      still refuses the line: the registration holds exactly that many
      quota units, so the number is not the cart's to change.

## Backlog (Phase 2 — release scope, invoice-settled registrations are a definition-of-done item)

- [x] B2C invoices above 250 € need a postal address (§14 UStG vs §33
      UStDV): a way for the operator or payer to complete the billing
      address on a cart order. Fixed (#4): `GermanVat::
      requiresBillingAddress()` states the 250 € gross ceiling once;
      `PlaceOrderFromCart::guardBillingAddress()` demands the address at
      checkout for a cart whose computed total is over it, and the cart
      page (`cart.billingAddressRequired`) asks for it up front.
      `IssueInvoiceOnOrderPaid` withholds the Rechnung — never issues one
      missing a legally required field, since an issued invoice can never
      be corrected in place (ADR-0031) — for any order (not just cart) that
      is paid over the threshold with no address; `OrderAdminController::
      addBillingAddress()` is the operator's retrofit, completing the party
      and issuing the invoice itself for an order that is already paid.
      Follow-ups this did not do: the same gap exists for a registration's
      instant-settled order, whose billing party can carry the same
      no-address shape — Registration's own `SubmitRegistrationData` is a
      different module and out of this issue's scope, but its billing party
      hits `IssueInvoiceOnOrderPaid`'s same guard today (withheld, not
      wrongly issued) and would benefit from asking for the address up
      front the way the cart now does. The admin retrofit form edits the
      whole billing party (name/org included) rather than only the address
      fields the issue named — deliberately, so an operator correcting a
      typo does not need a second form, but worth a second look if that
      turns out to be more surface than operators want.
- [x] A cart order whose payment failed has no re-pay link (the grace sweep
      cancels it after 60 min; the payer re-shops). Fixed: order-scoped
      re-pay at `GET`/`POST /pay/order/{order}` (`CheckoutController::
      showOrder()`/`startOrder()`), authorized on the order's buyer, reached
      from the order confirmation and the payment-failed mail (#5).
- [x] A PSP success arriving for an order already cancelled by
      `ExpireCarts`' checkout-grace sweep is a domain record, not a log
      line: `RecordPaymentSuccess` emits `PaymentUnapplied` and
      `Payment::scopeUnapplied()` finds every such row (ADR-0057 point 9,
      #6). Refunding it is still the operator's own decision — through the
      payment's own account, `RefundOrder`'s provider leg without a credit
      note, since a cancelled cart order has no document to correct.
- [x] Rückbuchungen und Disputes are modelled (ADR-0057, #166): the
      `payment_reversals` row, `PaymentStatus::Reversed`,
      `OrderStatus::PaymentReversed`, and all three adapters —
      Stripe's `charge.dispute.*` and `charge.refunded`, Mollie's
      chargeback and refund lists, Unzer's `chargeback` state, which is no
      longer a code comment.
- [x] A whole-order refund of a cart order cancels nothing on the
      registration side. Fixed without a new event: a whole refund of a
      cart order now emits one existing `OrderLineCancelled` per
      registration-kind line, reason `order_refunded` — the same event and
      producer pattern `CancelOrderForUnpaidReversal` already used, just a
      new value of the reason field (#73, whole-branch review finding 3).
- [x] `SendCreditNoteMail` names a cancellation credit note
      Stornierung/Cancellation rather than Erstattung/Refund (ADR-0057
      point 4): its own arm in the reason map and its own sentence in the
      `commerce.credit-note-issued` template.
- [ ] `commerce:reconcile-payments` skips *pending* Unzer payments (#71): a
      pending payment's `external_id` is the Paypage id and
      `UnzerApi::fetchPayment()` needs a payment id, which only exists from
      settlement onward. Add `fetchPaymentByOrderId` to `UnzerApi` /
      `SdkUnzerApi` (our `Payment` ULID is the Unzer `orderId`) and drop the
      provider filter on the pending pass.
- [ ] The `payment-reconciled` operator notice fires per corrected payment
      *and* per newly found reversal (#71), reusing the same kind because
      the underlying fact is identical ("the notification path lost
      something"). Revisit with its own kind if that turns out to be noisy.
- [ ] `CommerceGdprHandler` exports no `payment_reversals` rows (#166): a
      reversal carries a provider reference and a bank reason code tied to
      a payer, and the export should include it.
- [ ] The repayment-window form lives on the settlement-accounts console
      rather than on the brand edit page, because the brand page's only
      module extension point (`BrandFeature::settingsComponent`) renders
      solely for a per-brand feature that is switched on. If per-brand
      module settings belong on the brand page, Platform needs an ungated
      settings slot there.
- [ ] `commerce:probe-invoice-numbering`'s `--timeout` option takes no
      validation (#74): a zero, negative or non-numeric value is not
      rejected explicitly before being handed to the child process.
- [ ] `DrawInvoiceNumber` and `DrawCreditNoteNumber` each carry their own
      private `environmentMarker()` method (#74) — the same
      non-production-prefix logic duplicated rather than shared.
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
- [x] Local-dev webhooks (Stripe): `contrib/stripe-listen.sh` wraps
      `stripe listen --forward-to` against `POST /api/commerce/webhooks/stripe`,
      restricted to the events the app handles (#69). See README, "Stripe
      webhooks in local development".
- [ ] Local-dev webhooks (Mollie/Unzer): webhook URLs are still built from
      `app.url`, which these providers cannot reach from a WSL dev stack —
      still needs an inbound tunnel (ngrok/cloudflared, neither installed on
      the reference dev host) for end-to-end manual testing.
- [ ] `konventix:seed` never issues a refund, so `admin.credit-notes.document`
      (Task 3) is skipped by name in `DryRunTest` rather than walked — a real
      gap, not a "not a screen" skip. Have the seed refund one paid order per
      archetype once a natural spot for it exists in the seeding flow.
- [x] Partial credit notes with their own tax split (round 4, Task 23,
      D-REG-15, #7). `IssueCreditNote` takes explicit net/tax/gross instead
      of copying the invoice's, so the rate
      `CreditNoteDocumentRenderer` derives from those totals is the one that
      was charged, and it guards "the sum of an invoice's credit notes never
      exceeds its gross". `RefundUnitsOnRegistrationQuantityReduced` computes
      the amounts from the order line's snapshotted `unit_amount_net` and
      `tax_rate` with `TaxCalculator`'s formula;
      `RecordRefund`'s partial branch keeps the order `paid`, allows a
      second partial, emits no `OrderLineCancelled` and is idempotent on
      `credit_notes.reduction_key`. #7's three acceptance criteria are met
      for the case this flow produces — **still open**, and why #7 is not
      closed by itself: a general per-line allocation across an order with
      *mixed* tax rates, which needs a caller that names which lines a
      partial refund touches. #73's remaining console work (a partial-refund
      form on the order page) is untouched — the console button still
      refunds whole.
- [x] Cancellation as a domain flow (distinct from refund, which was
      already built): `OrderCancelled` now has a producer,
      `CancelOrderForUnpaidReversal` (ADR-0057 point 4) — reached from the
      daily `commerce:cancel-unpaid-reversals` sweep once `repay_due_on`
      has passed, or from an operator cancelling by hand before it — and
      its per-line counterpart `OrderLineCancelled` for a cart order.
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
- [x] The shop renders a product's net amount even when price_display is gross. Fixed (PAngV): `ShopProductData.unitAmountGross`, `ShopVariantData.unitAmountGross` and `CartLineData.unitAmountGross` are all computed server-side through `TaxCalculator` (#3). The order button reads "Order with obligation to pay" and the delivery statement is shown on the cart and pay pages (#75) — still open: a separate confirmation step in the cart is a product decision, not built here.
- [ ] `/my/orders` and `/my/orders/{order}` show no deadline beside a pay
      link, for a cart order or a registration order — the only surfaces
      `Settlement\PaymentWindow` does not reach (`MyOrderData`,
      `MyOrderDetailData`). The detail page is where a payer lands back from
      the provider, so it is the better of the two to say "held until …".
- [x] Every hosted checkout lands on the order's own page (bugfix round
      2026-09-21, Task 3): `GET /my/orders/{order}` (`commerce.mine.show`,
      `MyOrdersController::show()`, `MyOrderDetailData`,
      `commerce/my/Show.vue`), payer-only as a 404 like `/pay/order/{order}`
      (#185). The cart checkout used to hand every adapter the cart page as
      the one return URL, and the cart is `checked_out` by then — so paying
      ended on "Your cart is empty"; the repay page `pay/Order.vue` had no
      paid branch and greeted a successful payer with the "already settled"
      refusal. `PlaceOrderFromCart::handle()` now takes a
      `Closure(Order): string` for the return URL (the order does not exist
      when the controller calls it) and `CheckoutController::start()`/
      `startOrder()` return to the same page, so success, cancel and failure
      all land in one place that branches on the order's state: "Payment
      received", "Waiting for the payment confirmation from the provider"
      while the attempt is pending (the cancel path — a provider never
      reports a cancelled session), or "Pay now". `pay/Order.vue` gained the
      paid banner anyway, since a mailed link still reaches it. Follow-up:
      the page reads the order's state once; a payer back before the webhook
      landed sees "waiting" until they reload — polling the page (Inertia
      `usePoll`) while `paymentStatus` is pending would close that gap.
- [ ] Print the refund reason and note on the credit note document. Both are
      recorded on the audit trail and on the refund record today; the
      credit note itself always says "Grund: Erstattung" (#73).
- [ ] `OrderAdminController::refund()` catches `RuntimeException`, which is
      every reasoned refusal the settlement adapters make; an SDK transport
      failure (`\Stripe\Exception\ApiErrorException`,
      `\Mollie\Api\Exceptions\ApiException`) still reaches the operator as a
      500. Wrap SDK failures in a Commerce exception inside `Settlement/**`
      and the console gets a sentence instead of an error page.
- [x] #231 — the expiry sweeps ask the PSP before cancelling an order with
      an open hosted payment (`Settlement\HostedPaymentProbe`,
      `Actions\Concerns\AsksThePspBeforeCancelling`): paid is booked through
      the ordinary success path, money the provider says is travelling is
      held for a fortnight, a silent provider for a day, and the operators
      are told when either horizon releases an order. Unzer still cannot be
      asked about a pending Paypage — its `external_id` is a Paypage id and
      the API takes a payment id — so those orders are cancelled as before.
- [ ] #233 — `cart_lines` deadlock risk: an FK `KEY SHARE` → `NO KEY UPDATE`
      lock escalation on `carts` at four sites, plus two unordered
      multi-row lock sites. Filed, code untouched.
- [ ] PlaceRegistrationLineInCart's read-then-insert can lose a race to cart_lines_request_key_unique and throw a QueryException out of a listener, the same shape PlaceOrderFromSettlementRequest carries. The 2026-08-27 rush never surfaced it (0 × 5xx across 754 arrivals), so this stays a converge-on-conflict cleanup — catch the violation and re-read the line the winner created, as OpenCart::for() already does.
- [ ] The reversal mail still sends a cart order the neutral interim text
      rather than a pay link, though the link works since #186 — see
      `SendPaymentReversedMailOnPaymentReversed::send()` (#186 criterion 3,
      needs an owner).
- [ ] Reverb connection and broadcast metrics once Reverb offers a hook (#173).

- [ ] `pay/Show.vue` (309 lines) and `cart/Show.vue` (428) are over the
      ~300-line guideline (CLAUDE_extra.md); the cart page was already there
      before Task 17b added its line links. Extract the order-summary card
      the three pay/order pages share (`pay/Show.vue`, `pay/Order.vue`,
      `commerce/my/Show.vue` render the same `MyOrderLineData` list) and the
      cart's line row.

## Done

- [x] #234 / D-COM-20 — one end price on every public surface (round 4,
      Task 21): `Pricing\TaxCalculatorEndPrices` answers Platform's
      `Pricing\EndPriceCalculator` (bound beside `WaitingRoomGate`), one
      `TaxCalculator::calculate()` pass per batch with the §19 election and
      the currency read through `OperatorSettings`, a `null` entry for a
      line it cannot price. Commerce still computes every end price on the
      instance; what moved is the *caller* — `ShopController::withGrossPrices()`
      is gone and the `shop.index` slot no longer post-processes a
      contributor's payload, so a landing page and a static-page block get
      the same amount without a Commerce controller in the request.
      `smallBusinessScheme` stays a page prop, and the shop pages now name
      the brand's `currency` instead of a hardcoded symbol.

- [x] Every displayed entity links to its page on the payer's surfaces
      (bugfix round 2026-09-21, Task 17b): `commerce/my/Show.vue`'s heading
      links the registration an order settles (as the list does);
      `RegistrationSettlementData` and `RegistrationCartSettlementData`
      carry `orderUrl` (`/my/orders/{order}`, withheld for a payer-less
      order the page would 404) so the `registration.mine.show` slot links
      the invoice number, an "Order details" link and a checked-out cart's
      order; `MyOrderLineData.productUrl` beside `registrationUrl`, resolved
      by `Cart\ProductPageUrls` (published product, `commerce:shop` on —
      the two conditions `shop.show` answers under), and both pay pages now
      render that Data shape instead of inline line arrays; `CartLineData.url`
      links each cart line. `MyOrderDetailTest`, `RegistrationSettlementSlotTest`,
      `CheckoutPageTest`, `OrderRepayPageTest`, `CartCheckoutTest`.
- [x] Every operator notice links absolutely and the payer mails land on the
      order (bugfix round 2026-09-21, Task 9): `Operations\AdminUrl` is the
      one rule behind the six `commerce.operations` producers (the four
      `NotifyOperatorsOn*` listeners, `AsksThePspBeforeCancelling`,
      `ReconcilePayments`) — `OperatorSettings::adminBaseUrlForBrand()` when
      the operator has an `admin_host`, `config('app.url')` otherwise, never
      a bare `/admin/...` path; the topic is declared
      `TopicAudience::Operator`, so its inbox rows show in the console bell,
      not in a registrant's participant inbox. `OrderConfirmationMailData`,
      `PaymentFailedMailData` and `PaymentReversedMailData` carry `orderUrl`
      (`commerce.mine.show` on the brand host); the four payer templates
      link it beside the "My orders" list, and the payment-failed retry
      fallback lands on the order too.
- [x] Metrics for orders, payments, carts, stock and the waiting room in
      `src/Metrics` and `src/Listeners/Metrics` (ADR-0059, #173).

      `payments_pending` reports every brand on every scrape, zero included
      (hotfix 2026-09-13). The registry only sets the rows a gauge closure
      returns and the Redis storage keeps a series until it is overwritten,
      so a row emitted only while an order was pending outlived the order:
      dev carried `payments_pending{brand="sxlan"} 1` for two days against
      an empty table, and KonventixWebhookSilence with it, until the key
      was deleted by hand. `CommerceMetricsTest` scrapes twice through one
      registry to prove the row returns to `0`. Still open: a brand that is
      deleted, or has the waiting room switched off, leaves its last row in
      Redis indefinitely (nothing wipes the registry on deploy) — no gauge
      here can emit a label set it no longer knows about, so that needs a
      per-gauge wipe of stale label sets before the render, which promphp's
      storage has no API for.
- [x] Slice 24 (ADR-0058, #215) — Stripe Connect removed: one Stripe account
      per instance, its secret and webhook signing secret resolved
      row-before-environment by `Settlement\Stripe\StripeCredentials`
      (encrypted `stripe_settings` row per operator, set in the console's
      "Stripe account" card, or `STRIPE_SECRET`/`STRIPE_WEBHOOK_SECRET` from
      the environment). No connected accounts, no `Stripe-Account` header,
      no onboarding, no capability sweep. A brand's Stripe row is the
      enablement flag and carries `checkout_label` and the new
      `statement_descriptor_suffix` (≤10 characters: Stripe caps the account
      prefix and the suffix together at 22 and refuses the charge if they
      overflow, and the prefix cannot be read back since Connect went, so the
      rule keeps what survives the longest legal prefix — and the adapter
      retries once without the suffix if Stripe refuses it anyway, rather than
      500ing a payer); every Checkout Session and PaymentIntent carries
      `konventix_brand_id`, `konventix_brand_name` and
      `konventix_operator_id`. #161 closed as superseded, #198 and #199
      obsolete.

- [x] ADR-0057, the reversal deadline sweep and the operator/payer surface.
      The PSP webhook routes carry a rate limiter (#71): the named limiter
      `commerce-webhooks` in `CommerceServiceProvider`, 120/min per IP,
      applied to the `commerce/webhooks` group in `routes/api/commerce.php`.
      Generous on purpose — Mollie and Unzer answer every request with a
      provider call (ADR-0039), so this is a ceiling on abuse, never a
      shaper of real PSP traffic. `tests/WebhookThrottleTest.php`.

      A regular reconciliation against the provider reports what the
      webhooks missed (#71, ADR-0057 point 7):
      `commerce:reconcile-payments`, hourly. Pending payments older than 15
      minutes and younger than 7 days are re-asked (Stripe through the new
      `StripeApi::fetchCheckoutSession`, Mollie through `fetchPayment`);
      settled Mollie/Unzer payments of the last 90 days are re-read for
      chargebacks and dashboard refunds. Every correction logs at warning
      with the ids and raises the `payment-reconciled` operator notice.
      `tests/PaymentReconciliationTest.php`.

      The repayment deadline and its sweep (ADR-0057 point 4): the per-brand
      window (`commerce_brand_settings.reversal_repayment_days`, 1..60,
      default 7, set beside the brand's settlement accounts) feeding
      `RepaymentWindow`, and `commerce:cancel-unpaid-reversals` cancelling
      the order through `CancelOrderForUnpaidReversal` — credit note with
      reason `cancellation`, `OrderCancelled`/`OrderLineCancelled` with
      reason `reversal_unpaid`. An order whose admission was already used
      keeps its seat and becomes an open receivable instead.
      `tests/UnpaidReversalSweepTest.php`, `tests/CommerceBrandSettingsTest.php`.

      Operator and payer both see a reversal (ADR-0057 point 8): the
      `commerce.operations-notice` kinds `payment-reversed`,
      `dispute-opened`, `payment-unapplied` and `payment-reconciled`, and
      the payer mail `commerce.payment-reversed` — amount, the provider's
      reason in plain words (a map from SEPA return codes and Stripe
      dispute reasons; unknown codes get a neutral sentence, and none of
      them accuses), the deadline and the pay link. A cart order, which
      cannot be repaid online yet, gets `commerce.payment-reversed-manual`
      instead: the same facts without a link, naming the account page and
      the transfer or door payment the organizer can book.
      `tests/PaymentReversalMailsTest.php`.

      The orders console shows the reversals (ADR-0057 point 8, #166): a
      "Reversals & unapplied money" view over every order with an open or
      final reversal and every order holding a payment its status cannot
      take, and an order detail page that lists the reversal rows, names
      the repayment deadline and calls out an unapplied payment (ADR-0057
      point 9, #6). The operator can act on it too: cancel a reversed order
      by hand before the sweep would, and book a bank transfer or a door
      payment on it.

      A vanished Stripe session is a definite answer, not a failure to ask
      (#286): `SdkStripeApi::fetchCheckoutSession()` translates Stripe's own
      "No such checkout.session" (`resource_missing`) into
      `StripeSessionNotFoundException` rather than letting the vendor's
      `InvalidRequestException` be caught by name in the console.
      `ReconcilePayments` expires the payment (`failure_reason` =
      `provider_session_not_found`) when its order already reached a
      terminal state (cancelled, refunded); when the order is still open it
      changes nothing and instead names the order in a warning — logged and
      printed, not a per-run operator mail, because that case stays open by
      design until a human resolves it. Both counted separately from
      "corrected" in the summary line. No console action exists yet to void
      or expire a stuck payment by hand for the open-order case; that gap is
      left to a follow-up issue rather than folded in here.

- [x] Non-production invoice/credit-note numbers carry an environment
      marker (#74, ADR-0053): `DrawInvoiceNumber` and `DrawCreditNoteNumber`
      prepend the environment name as a leading segment off production
      (`STAGING-SXLAN-2026-00042`, `LOCAL-GS-SXLAN-2026-00042`) — the
      counter itself is untouched, only the rendering differs.
- [x] The invoice number range's concurrency is proven under real
      processes, not asserted: `commerce:probe-invoice-numbering`
      `{brand} {--workers=4} {--draws=25}` starts real child processes,
      each drawing numbers through `DrawInvoiceNumber` in its own
      transaction, and checks every worker exited 0, every drawn number is
      distinct, the counter ran 1..N with no gap (#74).

- [x] ADR-0048 (mail-and-receipts tranche): a paid order gets its Rechnung
      the moment it is paid, a refund leaves a Gutschrift behind, and the
      order lifecycle now talks to the people it affects.

      A paid order (Task 2): `IssueInvoiceOnOrderPaid` listens to
      `OrderPaid` and calls the existing `IssueInvoice`, now accepting an
      optional `PaymentSnapshotData` so `paid_at`/`paid_via`/`paid_provider`/
      `payment_reference` are written at the same moment as everything
      else, never as a later edit; idempotent per `order_id` via
      `lockForUpdate` taken before the existence check, since manual
      booking takes no lock of its own. Before this, only an
      invoice-settled registration order produced any document — a
      card-paid registration and every cart order got nothing; the new
      `orders.billing_party` column (`BillingPartyData` cast) closes that
      gap by giving a cart checkout a billing party to snapshot too. The
      invoice mail is two template keys sharing one payload
      (`commerce.invoice-issued`, `commerce.invoice-paid`) rather than one
      branching template, avoiding the Blade-directive-glued-to-text trap
      (`.ai/rules/app-modules-src.md`). The Factur-X half gets the prepaid
      treatment (`totalPrepaidAmount` = gross, `duePayableAmount` zero, no
      due term) so the PDF and the XML never disagree; `/my/orders` labels
      the paid document "Rechnung (bezahlt)".

      A refund (Task 3): `IssueCreditNote` dispatches
      `Jobs\GenerateCreditNoteDocument` `afterCommit()`, the same shape
      `IssueInvoice` uses, rendering the hybrid-ZUGFeRD `CREDITNOTE` (code
      381) from the credit note's own snapshot as one summary line
      ("Gutschrift zu Rechnung Nr. …"), archived to the same disk and
      retention rule as an invoice. `SendCreditNoteMail` sends
      `commerce.credit-note-issued` — one template for both refund and
      transfer, with pre-localised reason labels keeping the body
      branch-free. The payer's own copy lives at
      `/my/credit-notes/{creditNote}/document`, checked against the
      order's `payer_user_id` explicitly since a credit note's link is
      minted from the "my orders" list rather than from a render that
      already proved ownership; the operator console's twin is gated
      `ViewOrders`.

      The order lifecycle (Task 4): `SendOrderConfirmationOnOrderPlaced`
      (`OrderPlaced`, cart orders only — a direct order's payer already
      hears from Registration or Commerce's own Rechnung mail) sends
      `commerce.order-confirmation` with the order's lines, total and any
      reservation deadline; `SendPaymentFailedMailOnPaymentFailed`
      (`PaymentFailed`) sends `commerce.payment-failed` to the payer with a
      localised reason and a retry link, skipping a payer-less order with a
      `Log::warning` rather than an error. `NotifyOperatorsOnStockExhausted`
      and `NotifyOperatorsOnPaymentFailed` mail every
      `commerce.manage-products` holder through `commerce.operations-notice`
      on the new `commerce.operations` topic — digestable, but both
      triggers set `urgent: true` for an immediate nudge. New shared
      pieces: Platform's `Permissions\PermissionHolders::
      userIdsWithPermission()` (a pure query over spatie's own pivot
      tables) and `Operators\OperatorSettings::adminBaseUrlForBrand()`,
      the doorway both operator-notice listeners use to build an absolute
      admin-console link.
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
      connected, and — for Stripe — backed by a Stripe secret key on this
      instance. (ADR-0058 has since removed Stripe Connect: the capability
      check, `RefreshSettlementAccountCapabilities` and the daily sweep are
      gone with it, and a Stripe row is an enablement flag on the one
      account the instance holds.)
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

- [x] The confirmation mail still does not carry the pay link. Fixed:
      `Settlement\PayLink` now feeds the order confirmation mail too, plus
      the payment-failed mail and `MyOrderData` (#5).
- [x] Sales window per registration type: columns, admin form, landing-page
      filter, refusal at submission and at the cart line (#218).
- [x] #131 Registration orders expire past their payment window:
      `commerce:expire-registration-orders` cancels a pending
      `settles_type = registration` order past
      `Cart::CHECKOUT_GRACE_MINUTES` with `OrderCancelled` reason
      `payment_window_expired` — hosted population only, so a brand
      settling by bank transfer only is never touched by it. The window
      and the deadline shown on the pay page and in the payment-failed
      mail now share one predicate, `Settlement\PaymentWindow`.

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
