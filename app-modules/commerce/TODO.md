# Commerce — TODO

> Living document. Update it in the same PR that changes the code: check off
> what you completed, add follow-ups you discovered. Do not generate status
> dashboards, percentages, or summary tables here.

## Next (Phase 1 PoC — no payment; keep Commerce dormant and prove the boundary)

(nothing outstanding for Phase 1 — see Done)

## Backlog (Phase 2 — release scope, invoice-settled registrations are a definition-of-done item)

- [ ] Stripe Connect Standard and Mollie adapters against connected
      per-brand accounts (manual payment booking is built — see Done).
- [ ] `SettlementAccount` connection flow, surfaced in Platform's brand
      onboarding but owned here. `SettlementAdapter::markOrderPaid()` already
      takes an `$accountId` on every call (ADR-0007) but nothing validates it
      against a real account yet — `BookManualPayment` passes the `manual`
      pseudo-account.
- [ ] Dunning: `InvoiceOverdue` emission (scheduled job over `invoices.due_on`
      of unpaid orders), dunning levels as domain concepts, and
      `DunningLevelRaised`.
- [ ] Cancellation, refunds, and partial credit notes as domain flows with PSP
      calls as side effects. `CreditNoteIssued` per ADR-0031 — the immutable
      invoice makes credit notes the only correction path.
- [ ] Cart and vouchers.
- [ ] Bookkeeping export for the tax advisor; the GDPR exporter in
      `src/Gdpr/`, reconciled with statutory invoice retention (§147 AO).
- [ ] GoBD retention lifecycle for the `invoices` disk: the archive must
      survive brand deletion, operator changes, and storage migrations for
      ten years (ADR-0031 names the requirement; only the write-once
      archive itself is built). Production needs the disk pointed at
      durable storage.
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
