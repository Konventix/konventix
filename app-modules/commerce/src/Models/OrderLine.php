<?php

declare(strict_types=1);

namespace Konventix\Commerce\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Konventix\Commerce\Database\Factories\OrderLineFactory;
use Konventix\Commerce\Enums\TaxClass;
use Konventix\Platform\Models\Concerns\BelongsToBrand;

/**
 * One line of an order — the settlement-time snapshot of what was charged
 * (ADR-0017): declared class, *resolved* rate, computed amounts. The order
 * records what was charged; the registration type records only what was
 * offered. Nothing here is ever recomputed after the fact — the invoice
 * document's XML is built from these rows (ADR-0031).
 *
 * @property string $id
 * @property string $brand_id
 * @property string $order_id
 * @property int $position
 * @property string $label
 * @property int $quantity
 * @property numeric-string $unit_amount_net
 * @property TaxClass $tax_class
 * @property numeric-string $tax_rate
 * @property numeric-string $line_net
 * @property numeric-string $line_tax
 * @property numeric-string $line_gross
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class OrderLine extends Model
{
    use BelongsToBrand;

    /** @use HasFactory<OrderLineFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'order_id',
        'position',
        'label',
        'quantity',
        'unit_amount_net',
        'tax_class',
        'tax_rate',
        'line_net',
        'line_tax',
        'line_gross',
    ];

    /**
     * Within-module foreign key — a real FK and relation are fine
     * (ADR-0014 only forbids them across module boundaries).
     *
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'quantity' => 'integer',
            'tax_class' => TaxClass::class,
            'unit_amount_net' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'line_net' => 'decimal:2',
            'line_tax' => 'decimal:2',
            'line_gross' => 'decimal:2',
        ];
    }

    protected static function newFactory(): OrderLineFactory
    {
        return OrderLineFactory::new();
    }
}
