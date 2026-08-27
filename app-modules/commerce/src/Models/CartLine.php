<?php

declare(strict_types=1);

namespace Konventix\Commerce\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Konventix\Commerce\Database\Factories\CartLineFactory;
use Konventix\Commerce\Enums\CartLineKind;
use Konventix\Commerce\Enums\TaxClass;
use Konventix\Platform\Models\Concerns\BelongsToBrand;

/**
 * One thing a cart holds — a `ProductVariant`'s stock or a pending
 * registration's spot, distinguished by `kind` (ADR-0047 point 3).
 *
 * `ref_id` is a plain ULID (ADR-0014): for a `Product` line, a
 * `ProductVariant` id (same-module, but kept as a plain column since one
 * column serves two referents depending on `kind`); for a `Registration`
 * line, the settlement request's id. `request_key` is set only for a
 * registration line — a person may hold more than one pending
 * registration at once, so `ref_id` alone cannot dedupe it the way a
 * product line's `ref_id` can (`cart_lines_request_key_unique` is a
 * partial index for exactly this reason).
 *
 * `label`, `unit_amount_net` and `tax_class` are a snapshot taken the
 * moment the line is created — the same discipline `OrderLine` already
 * applies to a settled order, taken one step earlier here.
 *
 * @property string $id
 * @property string $brand_id
 * @property string $cart_id
 * @property CartLineKind $kind
 * @property string $ref_id
 * @property string|null $request_key
 * @property string $label
 * @property int $quantity
 * @property numeric-string $unit_amount_net
 * @property TaxClass $tax_class
 * @property int $position
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class CartLine extends Model
{
    use BelongsToBrand;

    /** @use HasFactory<CartLineFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'cart_id',
        'kind',
        'ref_id',
        'request_key',
        'label',
        'quantity',
        'unit_amount_net',
        'tax_class',
        'position',
    ];

    /**
     * Within-module foreign key — a real FK and relation are fine
     * (ADR-0014 only forbids them across module boundaries).
     *
     * @return BelongsTo<Cart, $this>
     */
    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => CartLineKind::class,
            'quantity' => 'integer',
            'unit_amount_net' => 'decimal:2',
            'tax_class' => TaxClass::class,
            'position' => 'integer',
        ];
    }

    protected static function newFactory(): CartLineFactory
    {
        return CartLineFactory::new();
    }
}
