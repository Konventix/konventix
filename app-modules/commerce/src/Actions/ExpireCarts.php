<?php

declare(strict_types=1);

namespace Konventix\Commerce\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Commerce\Enums\CartLineKind;
use Konventix\Commerce\Enums\CartStatus;
use Konventix\Commerce\Events\CartExpired;
use Konventix\Commerce\Events\CartLineExpired;
use Konventix\Commerce\Models\Cart;
use Konventix\Commerce\Models\CartLine;
use Konventix\Commerce\Models\ProductVariant;

/**
 * Expires every open cart past its `expires_at` (ADR-0047, Task 9): each
 * product line's stock is released, each registration line tells
 * Registration to cancel the still-pending registration it held
 * (`CartLineExpired`), and the cart itself moves to `expired`.
 *
 * Runs from the scheduler (`commerce:expire-carts`, every fifteen minutes)
 * across all brands, mirroring `Registration\Actions\ExpireWaitlistOffers`
 * exactly: `withoutBrandScope()` deliberately, one transaction per cart so
 * one bad row never blocks the rest of the sweep.
 */
final class ExpireCarts
{
    public function handle(): int
    {
        $expired = 0;

        /** @var list<Cart> $carts */
        $carts = Cart::withoutBrandScope()
            ->open()
            ->where('expires_at', '<=', now())
            ->get()
            ->all();

        foreach ($carts as $cart) {
            DB::transaction(function () use ($cart): void {
                /** @var list<CartLine> $lines */
                $lines = CartLine::withoutBrandScope()
                    ->where('cart_id', $cart->getKey())
                    ->get()
                    ->all();

                foreach ($lines as $line) {
                    if ($line->kind === CartLineKind::Product) {
                        $locked = ProductVariant::withoutBrandScope()
                            ->whereKey($line->ref_id)
                            ->lockForUpdate()
                            ->first();

                        $locked?->releaseStock($line->quantity);

                        continue;
                    }

                    event(new CartLineExpired(
                        cartId: $cart->getKey(),
                        brandId: $cart->brand_id,
                        kind: $line->kind->value,
                        refId: $line->ref_id,
                        requestKey: $line->request_key,
                        expiredAt: now()->toIso8601String(),
                    ));
                }

                $cart->forceFill(['status' => CartStatus::Expired])->save();

                event(new CartExpired(
                    cartId: $cart->getKey(),
                    brandId: $cart->brand_id,
                    payerUserId: $cart->payer_user_id,
                    expiredAt: now()->toIso8601String(),
                ));
            });

            $expired++;
        }

        return $expired;
    }
}
