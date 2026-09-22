<?php

declare(strict_types=1);

namespace Konventix\Commerce\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Commerce\Actions\Concerns\LocksTheCartBeforeItsLines;
use Konventix\Commerce\Enums\CartLineKind;
use Konventix\Commerce\Events\CartLineRemoved;
use Konventix\Commerce\Models\Cart;
use Konventix\Commerce\Models\CartLine;

/**
 * Closes #219's undo window at checkout: the buyer is paying for what is
 * left, so what they took out is taken out for good.
 *
 * Without this the window would have no end on the one path that matters
 * most. A cart whose order is *paid* is never selected by `ExpireCarts`
 * again — deliberately, see that class — so a soft-removed registration
 * line on a paid cart would sit in `cart_lines` forever, its registration
 * neither confirmed (it is not an order line) nor cancelled (nothing ever
 * emits for it), holding quota nobody can account for. That is the leak the
 * soft removal would have bought, and this is where it is paid off.
 *
 * Called by `PlaceOrderFromCart::handle()` **before** the order's own
 * transaction rather than inside it: this writes `cart_lines` and therefore
 * needs the `carts` row locked ahead of that write (#233), while
 * `placeOrder()` writes `orders`/`order_lines` and only then touches the
 * cart row. Keeping the two apart keeps each transaction's lock order the
 * single one the module agreed on instead of interleaving them.
 *
 * `CartLineRemoved` carries the line's own `removed_at` — the moment the
 * buyer pressed the button, not the moment the window closed. The event is
 * the answer to "what became of this line", and the honest answer is that
 * they removed it then, even though only now does anything act on it.
 *
 * Idempotent: the lines are gone afterwards, so a replayed checkout finds
 * nothing to resolve.
 */
final class ResolveRemovedCartLines
{
    use LocksTheCartBeforeItsLines;

    public function handle(Cart $cart): void
    {
        DB::transaction(function () use ($cart): void {
            $locked = $this->lockCart($cart->getKey());

            // Raw, not through `Cart::removedLines()`: a relation query
            // re-applies `BrandScope` on the related model even off a
            // parent that escaped it (`.ai/rules/app-modules-src.md`), and
            // this Action must behave the same whatever host resolved the
            // cart. `position` order for the same reason `ExpireCarts`
            // states it — the events it emits must not depend on heap order.
            /** @var list<CartLine> $lines */
            $lines = CartLine::withoutBrandScope()
                ->where('cart_id', $locked->getKey())
                ->whereNotNull('removed_at')
                ->orderBy('position')
                ->get()
                ->all();

            foreach ($lines as $line) {
                if ($line->kind === CartLineKind::Registration) {
                    event(new CartLineRemoved(
                        cartId: $locked->getKey(),
                        brandId: $locked->brand_id,
                        kind: $line->kind->value,
                        refId: $line->ref_id,
                        requestKey: $line->request_key,
                        removedAt: ($line->removed_at ?? now())->toIso8601String(),
                    ));
                }

                $line->delete();
            }
        });
    }
}
