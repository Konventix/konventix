<?php

declare(strict_types=1);

namespace Konventix\Commerce\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Commerce\Enums\CartLineKind;
use Konventix\Commerce\Events\StockExhausted;
use Konventix\Commerce\Exceptions\StockExhaustedException;
use Konventix\Commerce\Models\CartLine;
use Konventix\Commerce\Models\ProductVariant;
use LogicException;

/**
 * Changes a product line's quantity, consuming or releasing the delta
 * against the variant's stock under lock (ADR-0047, Task 9).
 *
 * Product lines only — a registration line's quantity is always one, fixed
 * by the registration itself, so this refuses with a `LogicException` a
 * controller translates to a 422 rather than silently doing nothing.
 */
final class ChangeCartLineQuantity
{
    public function handle(CartLine $line, int $quantity): CartLine
    {
        if ($line->kind !== CartLineKind::Product) {
            throw new LogicException('Only a product cart line supports a quantity change.');
        }

        return DB::transaction(function () use ($line, $quantity): CartLine {
            $locked = ProductVariant::query()
                ->whereKey($line->ref_id)
                ->lockForUpdate()
                ->firstOrFail();

            $delta = $quantity - $line->quantity;

            if ($delta > 0) {
                if (! $locked->hasStockFor($delta)) {
                    throw StockExhaustedException::forVariant($locked);
                }

                $locked->consumeStock($delta);
            } elseif ($delta < 0) {
                $locked->releaseStock(abs($delta));
            }

            $line->quantity = $quantity;
            $line->save();

            if ($delta > 0 && $locked->remaining() === 0) {
                event(new StockExhausted(
                    variantId: $locked->getKey(),
                    productId: $locked->product_id,
                    brandId: $locked->brand_id,
                    exhaustedAt: now()->toIso8601String(),
                ));
            }

            $line->cart->touchExpiry();

            return $line;
        });
    }
}
