<?php

declare(strict_types=1);

namespace Konventix\Commerce\Data;

use Spatie\LaravelData\Data;

/**
 * What `IssueInvoice` snapshots onto an invoice issued from `OrderPaid`
 * (ADR-0048): the money already landed, so the document says so instead of
 * naming a due date. Not persisted itself — `IssueInvoice` copies its
 * fields onto the immutable `Invoice` row at issuance.
 */
final class PaymentSnapshotData extends Data
{
    public function __construct(
        public readonly string $paidAt,
        public readonly string $paidVia,
        public readonly ?string $paymentReference,
    ) {}
}
