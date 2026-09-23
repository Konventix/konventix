<?php

declare(strict_types=1);

namespace Konventix\Commerce\Data;

use Spatie\LaravelData\Data;

/**
 * What `IssueInvoice` snapshots onto an invoice issued from `OrderPaid`
 * (ADR-0048): the money already landed, so the document says so instead of
 * naming a due date. Not persisted itself — `IssueInvoice` copies its
 * fields onto the immutable `Invoice` row at issuance.
 *
 * `provider` is the raw settlement key (`Payment::provider` /
 * `OrderPaid::settlementMethod`, e.g. `stripe`, `manual`). It carries no
 * human label: `IssueInvoice` translates `ProviderLabels::labelFor()` itself,
 * in the locale the invoice is issued in (ADR-0068 §6) — a label built by
 * the caller would be in whatever locale the caller happened to run in.
 * `InvoiceDocumentRenderer` still needs the raw key to pick the UNTDID 4461
 * payment-means code, never the translated label.
 */
final class PaymentSnapshotData extends Data
{
    public function __construct(
        public readonly string $paidAt,
        public readonly string $provider,
        public readonly ?string $paymentReference,
    ) {}
}
