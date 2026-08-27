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
 * `OrderPaid::settlementMethod`, e.g. `stripe`, `manual`), kept separate
 * from `paidVia` (the human label `ProviderLabels::labelFor()` already
 * produced from it): `InvoiceDocumentRenderer` needs the raw key to pick
 * the UNTDID 4461 payment-means code, and matching that off a translated,
 * operator-overridable label would be a silent trap the moment the label
 * text changes.
 */
final class PaymentSnapshotData extends Data
{
    public function __construct(
        public readonly string $paidAt,
        public readonly string $paidVia,
        public readonly string $provider,
        public readonly ?string $paymentReference,
    ) {}
}
