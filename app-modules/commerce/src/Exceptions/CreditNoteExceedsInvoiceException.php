<?php

declare(strict_types=1);

namespace Konventix\Commerce\Exceptions;

use RuntimeException;

/**
 * Thrown when issuing a credit note would take the sum of an invoice's
 * credit notes past the invoice's own gross (#7's third acceptance
 * criterion).
 *
 * Partial credit notes make this reachable for the first time: while every
 * credit note corrected a whole invoice the sum could only ever be one
 * document, and a second was refused by `RecordRefund`'s order status. A
 * per-unit credit note is issued against an order that stays `paid`, so
 * nothing above this stops an operator — or a replay keyed differently —
 * from crediting more than was ever invoiced. Credit notes are immutable
 * (ADR-0031), so the guard has to be before the write.
 */
final class CreditNoteExceedsInvoiceException extends RuntimeException
{
    public static function forInvoice(string $invoiceNumber, string $attempted, string $alreadyCredited, string $invoiceGross): self
    {
        return new self(sprintf(
            'Crediting %s against invoice %s would exceed it: %s of %s is already credited.',
            $attempted,
            $invoiceNumber,
            $alreadyCredited,
            $invoiceGross,
        ));
    }
}
