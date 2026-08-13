<?php

declare(strict_types=1);

namespace Konventix\Commerce\Exceptions;

use RuntimeException;

/**
 * Thrown when a credit note is requested for an order that was never invoiced.
 *
 * A credit note is the correction of an invoice (ADR-0031). An order paid at
 * checkout under `instant` settlement has no invoice to correct and no party
 * snapshot to address, so the document could not name who it is for. Refunding
 * such an order is legitimate — it is money moving without paperwork — and
 * `RefundOrder` handles it by not issuing a credit note at all.
 */
final class CreditNoteRequiresInvoiceException extends RuntimeException
{
    public static function forOrder(string $orderId): self
    {
        return new self(sprintf(
            'Order [%s] has no invoice, so there is nothing for a credit note to correct '
            .'(ADR-0031). Refunding it is still possible; it simply produces no document.',
            $orderId,
        ));
    }
}
