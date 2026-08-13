<?php

declare(strict_types=1);

namespace Konventix\Commerce\Events;

/**
 * An issued invoice passed its due date unpaid. Emitted once per invoice by
 * the dunning sweep (`Konventix\Commerce\Actions\RaiseDunningLevel`).
 *
 * Emitted once, not once per sweep: the sweep runs daily and re-emitting
 * would turn a single unpaid invoice into a daily notification for the same
 * fact. Escalation is `DunningLevelRaised`.
 *
 * Payload is scalars/ULIDs only (ADR-0004).
 */
final readonly class InvoiceOverdue
{
    public function __construct(
        public string $invoiceId,
        public string $invoiceNumber,
        public string $orderId,
        public string $brandId,
        public string $totalGross,
        public string $currency,
        public string $dueOn,
        public int $daysOverdue,
        public string $detectedAt,
    ) {}
}
