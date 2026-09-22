<?php

declare(strict_types=1);

namespace Konventix\Commerce\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Commerce\Actions\Concerns\LocksTheCartBeforeItsLines;
use Konventix\Commerce\Enums\CartLineKind;
use Konventix\Commerce\Events\CartLineRemoved;
use Konventix\Commerce\Models\CartLine;
use Konventix\Commerce\Models\ProductVariant;

/**
 * Removes one line from a cart (ADR-0047, Task 9). A product line releases
 * the stock it held; a registration line has none to release — instead it
 * emits `CartLineRemoved` so Registration can cancel the still-pending
 * registration the line was holding (ADR-0047 point 3, reusing the
 * waitlist sweep's shape).
 */
final class RemoveCartLine
{
    use LocksTheCartBeforeItsLines;

    public function handle(CartLine $line): void
    {
        DB::transaction(function () use ($line): void {
            // The variant first, as the other product paths do, so the one
            // order `product_variants` before `carts` holds everywhere in
            // the shop (#233).
            $locked = $line->kind === CartLineKind::Product
                ? ProductVariant::query()->whereKey($line->ref_id)->lockForUpdate()->first()
                : null;

            // The cart row before the line is deleted (#233): the delete
            // takes the FK's share lock on it and `touchExpiry()` below
            // raises the same row, which is the escalation two concurrent
            // removals deadlocked on.
            $this->lockCart($line->cart_id);

            $cart = $line->cart;

            if ($line->kind === CartLineKind::Product) {
                $locked?->releaseStock($line->quantity);
            } else {
                event(new CartLineRemoved(
                    cartId: $cart->getKey(),
                    brandId: $cart->brand_id,
                    kind: $line->kind->value,
                    refId: $line->ref_id,
                    requestKey: $line->request_key,
                    removedAt: now()->toIso8601String(),
                ));
            }

            $line->delete();
            $cart->touchExpiry();
        });
    }
}
