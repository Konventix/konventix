<?php

declare(strict_types=1);

namespace Konventix\Commerce\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Commerce\Actions\Concerns\LocksTheCartBeforeItsLines;
use Konventix\Commerce\Enums\CartLineKind;
use Konventix\Commerce\Models\CartLine;
use Konventix\Commerce\Models\ProductVariant;

/**
 * Removes one line from a cart (ADR-0047, Task 9) — and, since #219, the
 * two kinds part company here.
 *
 * **A product line is deleted, as it always was.** Its stock goes straight
 * back to the pool, which is the outcome anyone would want undone anyway,
 * and the way back is the product page the line links to: one click, and
 * nothing was lost in between. Holding stock open for an undo window would
 * mean refusing it to the next buyer for a line the first one just said
 * they did not want.
 *
 * **A registration line is soft-removed instead** — `removed_at` is
 * stamped, the row stays. This is the whole of #219's fix. The old
 * behaviour emitted `CartLineRemoved` right here, which cancelled the
 * registration behind the line in the same click: quota released, the seat
 * released, the buyer mailed, three seconds after they had confirmed it
 * and while their cart hold still had twenty minutes to run. An undo cannot
 * honestly reverse that, so the operator's decision (2026-09-22) was an
 * undo window rather than a confirmation dialog, and the only shape in
 * which an undo window is honest is one where **nothing irreversible
 * happens inside it**. The line stops counting — it leaves the list, the
 * totals, the cart badge and the checkout, because `Cart::lines()` filters
 * `removed_at` out — while the registration and its hold carry on
 * untouched.
 *
 * Who closes the window: nobody new. A soft-removed line rides the cart's
 * own `expires_at`, and the removal pushes that deadline the same
 * `LIFETIME_MINUTES` forward every other cart write does, so the buyer has
 * the full hold to change their mind. `RestoreCartLine` ends it early on an
 * undo; `ResolveRemovedCartLines` ends it when the buyer checks the rest of
 * the cart out; `ExpireCarts` ends it when the hold runs out — and the last
 * two are the ones that finally emit `CartLineRemoved`, so a removal nobody
 * undid still ends in a cancelled registration with the removal reason and
 * released quota. No second sweep exists, because the one that already owns
 * this cart's clock can carry it.
 *
 * The lock order #233 settled is unchanged: `product_variants`, then
 * `carts`, then the `cart_lines` write — a soft removal is still a write to
 * `cart_lines` and still takes the foreign key's share lock on the parent
 * row that `touchExpiry()` then raises.
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

            // The cart row before `cart_lines` is written (#233): the write
            // takes the FK's share lock on it and `touchExpiry()` below
            // raises the same row, which is the escalation two concurrent
            // removals deadlocked on.
            $this->lockCart($line->cart_id);

            $cart = $line->cart;

            if ($line->kind === CartLineKind::Product) {
                $locked?->releaseStock($line->quantity);
                $line->delete();
            } else {
                // #219: removed from the buyer's point of view, and from
                // every reader that goes through `Cart::lines()` — but
                // still holding what it held, so the undo has something to
                // give back.
                $line->forceFill(['removed_at' => now()])->save();
            }

            $cart->touchExpiry();
        });
    }
}
