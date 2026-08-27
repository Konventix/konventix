<?php

declare(strict_types=1);

namespace Konventix\Commerce\Actions;

use Illuminate\Support\Facades\DB;
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
    public function handle(CartLine $line): void
    {
        DB::transaction(function () use ($line): void {
            $cart = $line->cart;

            if ($line->kind === CartLineKind::Product) {
                $locked = ProductVariant::query()
                    ->whereKey($line->ref_id)
                    ->lockForUpdate()
                    ->first();

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
