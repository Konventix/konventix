<?php

declare(strict_types=1);

namespace Konventix\Commerce\Data;

use Konventix\Commerce\Enums\TaxClass;
use Spatie\LaravelData\Data;

/**
 * One credited line after Commerce has computed it (#7, D-0.8.2-1): the
 * order line it credits, how many units, and what that quantity comes to
 * at the line's **snapshotted** rate (ADR-0017).
 *
 * `CalculatedLineData`'s counterpart for the credit side. The difference is
 * the two fields that identify the invoice line — a settlement calculation
 * invents its lines, a credit note names existing ones — and the fact that
 * `taxRate` is read off `order_lines`, never resolved from `taxClass`.
 *
 * All amounts are decimal strings; money never travels as float.
 */
final class CreditedLineData extends Data
{
    /**
     * @param  numeric-string  $unitAmountNet
     * @param  numeric-string  $taxRate
     * @param  numeric-string  $lineNet
     * @param  numeric-string  $lineTax
     * @param  numeric-string  $lineGross
     */
    public function __construct(
        public readonly string $orderLineId,
        public readonly int $position,
        public readonly string $label,
        public readonly int $quantity,
        public readonly string $unitAmountNet,
        public readonly TaxClass $taxClass,
        public readonly string $taxRate,
        public readonly string $lineNet,
        public readonly string $lineTax,
        public readonly string $lineGross,
    ) {}

    /**
     * The row shape `credit_note_lines` stores — the snapshot itself.
     *
     * @return array<string, mixed>
     */
    public function toRow(): array
    {
        return [
            'order_line_id' => $this->orderLineId,
            'position' => $this->position,
            'label' => $this->label,
            'quantity' => $this->quantity,
            'unit_amount_net' => $this->unitAmountNet,
            'tax_class' => $this->taxClass,
            'tax_rate' => $this->taxRate,
            'line_net' => $this->lineNet,
            'line_tax' => $this->lineTax,
            'line_gross' => $this->lineGross,
        ];
    }
}
