<?php

declare(strict_types=1);

namespace Konventix\Commerce\Exceptions;

use RuntimeException;

/**
 * Thrown when a partial credit note's line selection cannot be honoured as
 * written (#7, D-0.8.2-1) — an empty pick, or a line that is not part of
 * the order being credited.
 *
 * Separate from `CreditNoteExceedsInvoiceException`, which is about how
 * much is left to credit. This one is about whether the selection refers to
 * anything at all, and it is a `RuntimeException` so the console's refund
 * form turns it into a 422 the operator can read rather than a 500
 * (`OrderAdminController::refund()`).
 */
final class CreditNoteLineSelectionException extends RuntimeException
{
    public static function empty(string $orderId): self
    {
        return new self(sprintf(
            'No lines were selected for the credit note against order [%s]; a partial credit note credits named lines and quantities, never a bare amount (D-0.8.2-1).',
            $orderId,
        ));
    }

    /**
     * Zero or fewer units of a line is not a credit note, and a fraction of
     * one unit is not expressible here at all (see
     * `CreditNoteLineSelectionData`). The console cannot produce either —
     * `#[Min(1)]` on an integer refuses them at the request edge — so this
     * guards the Action's own callers, which construct the selection in PHP.
     */
    public static function nonPositiveQuantity(string $orderLineId, int $quantity): self
    {
        return new self(sprintf(
            'A credit note credits whole units: [%d] of line [%s] is not a quantity that can be credited.',
            $quantity,
            $orderLineId,
        ));
    }

    public static function unknownLine(string $orderLineId, string $orderId): self
    {
        return new self(sprintf(
            'Line [%s] is not part of order [%s], so it cannot be credited against its invoice.',
            $orderLineId,
            $orderId,
        ));
    }
}
