<?php

declare(strict_types=1);

namespace Konventix\Commerce\Actions;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Konventix\Commerce\Enums\CartStatus;
use Konventix\Commerce\Models\Cart;

/**
 * Finds a payer's open cart, or opens one (ADR-0047, Task 9). No
 * dependencies and no side effects beyond the cart itself, so this is a
 * static doorway rather than an instantiated Action — the shape every
 * caller (a shop controller with a resolved brand, a queue worker, an
 * admin tool with none) actually needs.
 *
 * `brand_id` is set explicitly on the new row, never from ambient
 * `BrandContext` (ADR-0010): a queue or admin caller has no brand context
 * to fall back on.
 *
 * The partial unique index `carts_one_open_per_payer` makes a second
 * concurrent open cart for the same payer impossible — the same
 * read-then-write race `EnterCompetition`'s `entrants_one_live_per_team`
 * closes. This attempts the insert and, on a lost race, re-reads the cart
 * the other request created rather than treating the conflict as an error.
 */
final class OpenCart
{
    public static function for(string $brandId, string $payerUserId): Cart
    {
        $existing = self::findOpen($brandId, $payerUserId);

        if ($existing !== null) {
            return $existing;
        }

        return DB::transaction(function () use ($brandId, $payerUserId): Cart {
            $cart = new Cart([
                'payer_user_id' => $payerUserId,
                'status' => CartStatus::Open,
                'expires_at' => now()->addMinutes(Cart::LIFETIME_MINUTES),
            ]);
            // brand_id explicit, not ambient context (ADR-0010) — the
            // caller may have no resolved brand at all.
            $cart->brand_id = $brandId;

            try {
                $cart->save();
            } catch (QueryException $e) {
                if (str_contains($e->getMessage(), 'carts_one_open_per_payer')) {
                    return self::findOpen($brandId, $payerUserId)
                        ?? throw $e;
                }

                throw $e;
            }

            return $cart;
        });
    }

    private static function findOpen(string $brandId, string $payerUserId): ?Cart
    {
        return Cart::withoutBrandScope()
            ->where('brand_id', $brandId)
            ->where('payer_user_id', $payerUserId)
            ->open()
            ->first();
    }
}
