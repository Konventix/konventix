<?php

declare(strict_types=1);

namespace Konventix\Commerce\Models;

use Carbon\CarbonInterface;
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
 * applies to a settled order, taken one step earlier here. `sale_starts_at`
 * and `sale_ends_at` join that snapshot for a registration line (#218):
 * Commerce never reads Registration's table (ADR-0004), so the window the
 * settlement request declared is kept here for audit — an answer to "what
 * window was this line placed under", read by nobody at render time
 * (whole-branch review finding 11).
 *
 * Like every other column on this line, the window is frozen at creation and
 * nothing refreshes it — an operator extending or shortening a sale
 * afterwards does not reach a line already in a cart. `isOnSale()` therefore
 * answers "was this line's sale open at `$now`, as of what we were told",
 * never "is the type on sale now". Only `PlaceRegistrationLineInCart` acts on
 * it; the checkout deliberately does not (see `PlaceOrderFromCart`).
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
 * @property Carbon|null $sale_starts_at
 * @property Carbon|null $sale_ends_at
 * @property array<string, mixed>|null $binding_context
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class CartLine extends Model
{
    use BelongsToBrand;

    /** @use HasFactory<CartLineFactory> */
    use HasFactory, HasUlids;

    /**
     * An explicit null rather than an absent attribute, for the reason
     * `OrderLine` gives: `shouldBeStrict()` refuses to read what a freshly
     * made instance never received, and a product line carries no bag.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'binding_context' => null,
    ];

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
        'sale_starts_at',
        'sale_ends_at',
        'binding_context',
    ];

    /**
     * Whether this line may still be turned into money at `$now` (#218).
     *
     * Both bounds are nullable and null means "no bound", so every product
     * line — which carries neither — is always on sale, and a registration
     * line from a type with no window behaves exactly as it did before the
     * window existed. Start inclusive, end exclusive, matching
     * `RegistrationType::isOnSale()` on the other side of the boundary.
     */
    public function isOnSale(?CarbonInterface $now = null): bool
    {
        $now ??= now();

        if ($this->sale_starts_at !== null && $now->lessThan($this->sale_starts_at)) {
            return false;
        }

        return $this->sale_ends_at === null || $now->lessThan($this->sale_ends_at);
    }

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
            'sale_starts_at' => 'datetime',
            'sale_ends_at' => 'datetime',
            // ADR-0066 D-CPL-06: the settlement request's own scalars,
            // carried to the order line at checkout and interpreted by
            // nothing in this module.
            'binding_context' => 'array',
        ];
    }

    protected static function newFactory(): CartLineFactory
    {
        return CartLineFactory::new();
    }
}
