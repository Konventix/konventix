<?php

declare(strict_types=1);

namespace Konventix\Commerce\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Commerce\Actions\Concerns\LocksTheCartBeforeItsLines;
use Konventix\Commerce\Models\CartLine;

/**
 * The undo behind #219's window: a soft-removed registration line goes back
 * into the cart.
 *
 * It is a one-column write and nothing else, because `RemoveCartLine`
 * deliberately left nothing to repair — no cancellation to reverse, no
 * quota to re-take, no seat to win back, no mail to unsend. That is the
 * point of removing softly rather than cancelling and building a restore
 * path: a restore path would have to re-win a race it cannot be sure of
 * winning, and would have nothing to say to the buyer when it lost.
 *
 * Idempotent by its own lookup: `CartController::restoreLine()` resolves
 * the line through `Cart::removedLines()`, so a second undo of the same
 * line — a double-tapped button, a replayed request — finds nothing to
 * restore and 404s rather than writing again.
 *
 * `touchExpiry()` for the same reason every other cart write calls it: the
 * buyer is shopping, and the hold that carries the line they just put back
 * should not lapse while they finish.
 *
 * Lock order as #233 settled it: the `carts` row before the `cart_lines`
 * write. No variant is involved — a product line is never soft-removed
 * (`RemoveCartLine`).
 */
final class RestoreCartLine
{
    use LocksTheCartBeforeItsLines;

    public function handle(CartLine $line): void
    {
        DB::transaction(function () use ($line): void {
            $this->lockCart($line->cart_id);

            $cart = $line->cart;

            $line->forceFill(['removed_at' => null])->save();

            $cart->touchExpiry();
        });
    }
}
