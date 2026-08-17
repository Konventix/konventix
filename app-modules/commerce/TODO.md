# Commerce — TODO

> Living document. Update it in the same PR that changes the code: check off
> what you completed, add follow-ups you discovered. Do not generate status
> dashboards, percentages, or summary tables here.

## Next (Phase 1 PoC — no payment; keep Commerce dormant and prove the boundary)

(nothing outstanding for Phase 1 — see Done)

## Backlog (Phase 2 — release scope, invoice-settled registrations are a definition-of-done item)

- [ ] Bookkeeping export follow-ups (ADR-0040 accepts all of these):
      deviating fiscal years are unsupported (WJ-Beginn is hardcoded to
      January 1); no per-customer debtor master export (collective debtor
      only); `Leistungsdatum` is not filled (the advisor may want the event
      date rather than the invoice date for period assignment — ask them);
      no SKR04 one-click preset on the settings form.
- [ ] Stripe go-live readiness check: nothing verifies
      `configuration.merchant.capabilities.card_payments.status === 'active'`
      before offering checkout on a freshly onboarded account — until Stripe
      activates it, `startCheckout` fails at Stripe rather than being hidden.
      Needs a fetch-account seam method and a guard/badge on the admin page.
- [ ] Mollie/Unzer key validation at connect time: the key is stored and
      trusted; a typo surfaces at the first checkout, not in the connect
      form. Validate with a cheap authenticated API call before saving.
- [ ] Nothing surfaces the pay link (`/pay/registration/{ulid}`) to the
      payer yet: Registration's post-submit page and the Messaging
      confirmation mail should carry it for `instant` types. Registration
      needs only the ULID it already has.
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
- [ ] Cart and vouchers.
- [ ] The GDPR exporter in `src/Gdpr/`, reconciled with statutory invoice
      retention (§147 AO).
- [ ] GoBD retention lifecycle for the `invoices` disk: the archive must
      survive brand deletion, operator changes, and storage migrations for
      ten years (ADR-0031 names the requirement; only the write-once
      archive itself is built). Production needs the disk pointed at
      durable storage — ADR-0043 makes that an S3 driver choice and puts
      Object Lock/versioning on the deployment checklist, since Garage
      implements neither.
- [ ] Retention *reporting* (ADR-0043 point 9): a scheduled job that names
      archived documents past their window plus a manual artisan command.
      Deliberately no automated deletion and no startup check — under
      Octane "startup" is every worker boot. What deletion means (§147 AO
      counts ten years from the end of the calendar year of issue) is
      deferred to its own decision, tracked in ROADMAP Phase 3.
- [ ] VIES validation for reverse-charge VAT IDs — ADR-0030 accepts the
      unvalidated-ID gap at release; the organizer's bookkeeper must be told.
      Revisit before any real cross-border exhibitor volume.
- [ ] `OrderPartiallyPaid` / `PaymentReceived` as first-class payment records
      — `BookManualPayment` currently books full settlement only.
- [ ] Invoice-number prefix settings UI: the per-(brand, year) range row
      carries the prefix (defaulting from the brand slug on first draw);
      editing it needs a settings screen and only ever applies to future
      ranges.

## Done

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
      kept separate from `ManageSettlementAccount` (not wired to
      `permissions:sync` or Policies yet).

<!-- Move completed items here with the PR that finished them. -->
