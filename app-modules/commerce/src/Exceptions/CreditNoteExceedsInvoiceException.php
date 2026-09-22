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

    /**
     * The per-line half of the same criterion (#7, D-0.8.2-1).
     *
     * The invoice total is necessary but not sufficient once a credit note
     * names lines: an invoice of 200.00 + 100.00 has 100.00 of room left
     * after its first line is credited in full, and without this the second
     * credit note could spend that room on the *same* line again — twice
     * the units, at the right total, against a sale that only ever happened
     * once. `credit_note_lines` is the ledger this reads.
     *
     * Refused, never clamped to what is left: a request to credit three of
     * two units is a mistake about what was sold, and silently crediting
     * two would turn it into a document nobody meant to issue.
     */
    public static function forLine(string $label, int $attempted, int $alreadyCredited, int $sold): self
    {
        return new self(sprintf(
            'Crediting %d unit(s) of "%s" would exceed the line: %d of %d unit(s) is already credited.',
            $attempted,
            $label,
            $alreadyCredited,
            $sold,
        ));
    }
}
