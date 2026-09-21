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
 * `settles_kind`/`settles_ref` are the per-line counterpart of `Order`'s
 * own `settles_type`/`settles_id` (ADR-0047): a cart checkout produces one
 * order whose lines settle a mix of products and registrations, so the
 * "what does this line pay for" reference has to live per line rather than
 * once per order. Both are nullable plain ULIDs (ADR-0014) — Task 11
 * writes them; a pre-cart order line, like every order Commerce created
 * before this task, carries neither.
 *
 * @property string $id
 * @property string $brand_id
 * @property string $order_id
 * @property int $position
 * @property string $label
 * @property string|null $settles_kind
 * @property string|null $settles_ref
 * @property int $quantity
 * @property numeric-string $unit_amount_net
 * @property TaxClass $tax_class
 * @property numeric-string $tax_rate
 * @property numeric-string $line_net
 * @property numeric-string $line_tax
 * @property numeric-string $line_gross
 * @property array<string, mixed>|null $binding_context
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class OrderLine extends Model
{
    use BelongsToBrand;

    /** @use HasFactory<OrderLineFactory> */
    use HasFactory, HasUlids;

    /**
     * An explicit null rather than an absent attribute: `shouldBeStrict()`
     * refuses to read an attribute a freshly made instance never received,
     * and every line placed by a path that carries no bag — every product
     * line, and every registration line from before ADR-0066 — names none.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'binding_context' => null,
    ];

    protected $fillable = [
        'order_id',
        'position',
        'label',
        'settles_kind',
        'settles_ref',
        'quantity',
        'unit_amount_net',
        'tax_class',
        'tax_rate',
        'line_net',
        'line_tax',
        'line_gross',
        'binding_context',
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
            // ADR-0066 D-CPL-06: the settlement request's own scalars, cast
            // back to an array and read by nobody in this module. Commerce
            // carries it; Compliance is the only module that knows what any
            // of its keys mean.
            'binding_context' => 'array',
        ];
    }

    protected static function newFactory(): OrderLineFactory
    {
        return OrderLineFactory::new();
    }
}
