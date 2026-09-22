<?php

declare(strict_types=1);

namespace Konventix\Commerce\Actions;

use Konventix\Commerce\Data\CreditedLineData;
use Konventix\Commerce\Data\CreditedLinesData;
use Konventix\Commerce\Data\CreditNoteLineSelectionData;
use Konventix\Commerce\Exceptions\CreditNoteExceedsInvoiceException;
use Konventix\Commerce\Exceptions\CreditNoteLineSelectionException;
use Konventix\Commerce\Models\CreditNoteLine;
use Konventix\Commerce\Models\Order;
use Konventix\Commerce\Models\OrderLine;
use Konventix\Commerce\Tax\TaxCalculator;

/**
 * Turns "these lines, these quantities" into the net, VAT and gross a
 * credit note will carry (#7, D-0.8.2-1) — and refuses the selection where
 * it cannot be honoured.
 *
 * **Why a selection and not a sum.** `IssueCreditNote` used to take a gross
 * amount and inherit the invoice's tax allocation. On an invoice that mixes
 * rates that is not a rounding nicety, it is a wrong VAT figure: crediting
 * the 7% line of a 19%/7% invoice produced the *invoice's* blended split,
 * and the document then stated a rate nobody had ever charged. There is no
 * way to recover the right answer from a gross alone, because the gross
 * does not say which line it came from. Naming the lines does.
 *
 * **Each line is computed, never allocated** — `TaxCalculator::lineAtRate()`
 * with the line's own `tax_rate`, which is a settlement-time snapshot
 * (ADR-0017). Not `TaxCalculator::calculate()`: that resolves a declared
 * `TaxClass` to *today's* statutory rate and today's treatment, and an
 * order credited next year must be credited at the rate it was charged at.
 * Rounding is the calculator's — per line, half-up to cents, then summed —
 * so the totals are exactly the sum of the printed lines and there is no
 * second policy to disagree with the first.
 *
 * **Two guards, both refusals.** A line that is not on this order is
 * rejected outright. A line whose remaining, uncredited quantity is smaller
 * than what was asked for is rejected too — not clamped to what is left:
 * the operator's mistake would otherwise become a legal document that
 * cannot be corrected afterwards (ADR-0031). The invoice-total guard lives
 * in `IssueCreditNote`, inside the transaction that draws the number; this
 * one is per line, and both are needed (see
 * `CreditNoteExceedsInvoiceException::forLine()`).
 *
 * Called twice per refund on purpose. `RefundOrder` calls it **before** the
 * provider, so a selection that cannot be credited never moves money;
 * `IssueCreditNote` calls it again inside the transaction that holds the
 * order lock, which is what makes the answer authoritative against a
 * concurrent second credit note.
 *
 * Escapes `BrandScope` explicitly (ADR-0010): a refund is made from the
 * operator host, which resolves no brand, and an unescaped relation read
 * would silently come back empty (`.ai/rules/app-modules-src.md`).
 */
final class ResolveCreditNoteLines
{
    public function __construct(private readonly TaxCalculator $taxCalculator) {}

    /**
     * @param  list<CreditNoteLineSelectionData>  $selection
     *
     * @throws CreditNoteLineSelectionException
     * @throws CreditNoteExceedsInvoiceException
     */
    public function handle(Order $order, array $selection): CreditedLinesData
    {
        $wanted = $this->unitsPerLine($order, $selection);

        /** @var array<string, OrderLine> $lines */
        $lines = OrderLine::withoutBrandScope()
            ->where('order_id', $order->getKey())
            ->whereIn('id', array_keys($wanted))
            ->orderBy('position')
            ->get()
            ->keyBy('id')
            ->all();

        foreach (array_keys($wanted) as $orderLineId) {
            if (! isset($lines[$orderLineId])) {
                throw CreditNoteLineSelectionException::unknownLine($orderLineId, (string) $order->getKey());
            }
        }

        $alreadyCredited = CreditNoteLine::creditedQuantitiesFor($order->brand_id, array_keys($wanted));

        $credited = [];
        $totalNet = '0.00';
        $totalTax = '0.00';

        // `$lines` is ordered by `position`, so the document reads in the
        // invoice's own order however the caller sorted its selection.
        foreach ($lines as $orderLineId => $line) {
            $quantity = $wanted[$orderLineId];
            $credit = $alreadyCredited[$orderLineId] ?? 0;

            if ($quantity > $line->quantity - $credit) {
                throw CreditNoteExceedsInvoiceException::forLine($line->label, $quantity, $credit, $line->quantity);
            }

            $calculated = $this->taxCalculator->lineAtRate(
                label: $line->label,
                quantity: $quantity,
                unitAmountNet: (string) $line->unit_amount_net,
                taxClass: $line->tax_class,
                taxRate: (string) $line->tax_rate,
            );

            $credited[] = new CreditedLineData(
                orderLineId: $orderLineId,
                position: $line->position,
                label: $line->label,
                quantity: $quantity,
                unitAmountNet: $calculated->unitAmountNet,
                taxClass: $calculated->taxClass,
                taxRate: $calculated->taxRate,
                lineNet: $calculated->lineNet,
                lineTax: $calculated->lineTax,
                lineGross: $calculated->lineGross,
            );

            $totalNet = bcadd($totalNet, $calculated->lineNet, 2);
            $totalTax = bcadd($totalTax, $calculated->lineTax, 2);
        }

        return new CreditedLinesData(
            lines: $credited,
            totalNet: $totalNet,
            totalTax: $totalTax,
            totalGross: bcadd($totalNet, $totalTax, 2),
        );
    }

    /**
     * The selection collapsed to one entry per order line.
     *
     * A caller naming the same line twice means one credited line of the
     * summed quantity, not two rows for the same position on one document —
     * the quantities are what is being asked for, and the document prints
     * the line once. Nothing is dropped, so no amount goes missing in the
     * collapse.
     *
     * @param  list<CreditNoteLineSelectionData>  $selection
     * @return array<string, int>
     */
    private function unitsPerLine(Order $order, array $selection): array
    {
        if ($selection === []) {
            throw CreditNoteLineSelectionException::empty((string) $order->getKey());
        }

        $wanted = [];

        foreach ($selection as $picked) {
            if ($picked->quantity < 1) {
                throw CreditNoteLineSelectionException::nonPositiveQuantity($picked->orderLineId, $picked->quantity);
            }

            $wanted[$picked->orderLineId] = ($wanted[$picked->orderLineId] ?? 0) + $picked->quantity;
        }

        return $wanted;
    }
}
