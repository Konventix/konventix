<?php

declare(strict_types=1);

namespace Konventix\Commerce\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Commerce\Enums\CartLineKind;
use Konventix\Commerce\Events\StockExhausted;
use Konventix\Commerce\Exceptions\StockExhaustedException;
use Konventix\Commerce\Models\Cart;
use Konventix\Commerce\Models\CartLine;
use Konventix\Commerce\Models\ProductVariant;

/**
 * Adds a quantity of one `ProductVariant` to a cart, consuming its stock
 * the moment it lands (ADR-0047 point 2 — stock is a quota, held from the
 * moment it is reserved, not from checkout). A second add of the same
 * variant merges into the existing line rather than creating a duplicate,
 * mirroring an order's one-line-per-item shape.
 *
 * Runs on the brand's own host (the shop, Task 11), so the ordinary
 * brand-scoped query locks the variant — unlike the sweep and the admin
 * console, which escape the scope deliberately (`app-modules-src.md`).
 *
 * `$quantity` is a delta for *this call*, not a target total: adding 3 then
 * 2 of the same variant consumes 5 and leaves one line at quantity 5.
 */
final class AddProductToCart
{
    public function handle(Cart $cart, ProductVariant $variant, int $quantity): CartLine
    {
        return DB::transaction(function () use ($cart, $variant, $quantity): CartLine {
            $locked = ProductVariant::query()
                ->with('product')
                ->whereKey($variant->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->hasStockFor($quantity)) {
                throw StockExhaustedException::forVariant($locked);
            }

            $locked->consumeStock($quantity);

            $line = CartLine::query()
                ->where('cart_id', $cart->getKey())
                ->where('kind', CartLineKind::Product)
                ->where('ref_id', $locked->getKey())
                ->first();

            if ($line !== null) {
                $line->quantity += $quantity;
                $line->save();
            } else {
                $line = new CartLine([
                    'cart_id' => $cart->getKey(),
                    'kind' => CartLineKind::Product,
                    'ref_id' => $locked->getKey(),
                    'label' => $locked->label === null
                        ? $locked->product->name
                        : "{$locked->product->name} — {$locked->label}",
                    'quantity' => $quantity,
                    'unit_amount_net' => $locked->effectiveUnitAmountNet(),
                    'tax_class' => $locked->product->tax_class,
                    'position' => $cart->lines()->count(),
                ]);
                $line->brand_id = $cart->brand_id;
                $line->save();
            }

            if ($locked->remaining() === 0) {
                event(new StockExhausted(
                    variantId: $locked->getKey(),
                    productId: $locked->product_id,
                    brandId: $locked->brand_id,
                    exhaustedAt: now()->toIso8601String(),
                ));
            }

            $cart->touchExpiry();

            return $line;
        });
    }
}
