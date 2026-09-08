<?php

declare(strict_types=1);

namespace Konventix\Commerce\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Konventix\Commerce\Database\Factories\ProductVariantFactory;
use Konventix\Platform\Models\Concerns\BelongsToBrand;

/**
 * One purchasable line of a `Product` — a size, a colour, or (for a product
 * with no variation) the single implicit row Task 7's consumer `Action`
 * creates so every product has exactly one stock counter to lock.
 *
 * Stock follows `Registration\Models\Quota`'s contract exactly, copied
 * rather than imported (ADR-0004 forbids the cross-module Eloquent import):
 * `stock_capacity`/`stock_consumed` are counters only. `consumeStock()` and
 * `releaseStock()` never lock the row or open a transaction themselves —
 * callers are responsible for `lockForUpdate()` inside their own Action's
 * `DB::transaction`, exactly like `Quota::consume()`/`release()`. The
 * consumer Action emits `StockExhausted` the moment `remaining()` reaches
 * zero.
 *
 * @property string $id
 * @property string $brand_id
 * @property string $product_id
 * @property string|null $label
 * @property string|null $sku
 * @property numeric-string|null $unit_amount_net
 * @property int $stock_capacity
 * @property int $stock_consumed
 * @property int $position
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ProductVariant extends Model
{
    use BelongsToBrand;

    /** @use HasFactory<ProductVariantFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'label',
        'sku',
        'unit_amount_net',
        'stock_capacity',
        'stock_consumed',
        'position',
    ];

    /**
     * Within-module foreign key — a real FK and relation are fine
     * (ADR-0014 only forbids them across module boundaries).
     *
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function remaining(): int
    {
        return max(0, $this->stock_capacity - $this->stock_consumed);
    }

    public function hasStockFor(int $quantity): bool
    {
        return $this->remaining() >= $quantity;
    }

    /**
     * Counters only (`Quota`'s contract, copied not imported) — the caller
     * must already hold `lockForUpdate()` inside its own transaction.
     */
    public function consumeStock(int $quantity): void
    {
        $this->stock_consumed += $quantity;
        $this->save();
    }

    /**
     * Floors at 0, like `Quota::release()` — a release can never be pushed
     * past what was actually consumed.
     */
    public function releaseStock(int $quantity): void
    {
        $this->stock_consumed = max(0, $this->stock_consumed - $quantity);
        $this->save();
    }

    /**
     * The variant's own price when it declares one, else the product's — a
     * size that costs the same as every other size needs no override row.
     *
     * @return numeric-string both sides of the coalesce are, and callers
     *                        hand the result straight to `TaxCalculator`.
     */
    public function effectiveUnitAmountNet(): string
    {
        return $this->unit_amount_net ?? $this->product->unit_amount_net;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'unit_amount_net' => 'decimal:2',
            'stock_capacity' => 'integer',
            'stock_consumed' => 'integer',
            'position' => 'integer',
        ];
    }

    protected static function newFactory(): ProductVariantFactory
    {
        return ProductVariantFactory::new();
    }
}
