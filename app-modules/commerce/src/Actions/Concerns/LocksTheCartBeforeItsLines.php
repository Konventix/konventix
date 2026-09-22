<?php

declare(strict_types=1);

namespace Konventix\Commerce\Actions\Concerns;

use Konventix\Commerce\Models\Cart;

/**
 * **The parent `carts` row is locked before the first write to
 * `cart_lines`** (#233).
 *
 * On PostgreSQL a foreign key is not free: every INSERT, UPDATE or DELETE on
 * `cart_lines` makes the transaction take a `FOR KEY SHARE` lock on the
 * parent `carts` row, so that nobody can delete or re-key the cart the child
 * row points at. `Cart::touchExpiry()` then writes `expires_at` on that same
 * row at the end of the transaction, which needs `FOR NO KEY UPDATE`. Two
 * concurrent transactions on one cart therefore both hold `KEY SHARE` and
 * both ask to be raised to `NO KEY UPDATE`: neither can have it while the
 * other holds its share, Postgres raises SQLSTATE 40P01 and kills one of
 * them. It reproduced in `SalesWindowTest` during the night of 2026-09-08.
 *
 * Nothing locks `cart_lines` anywhere, so this was never two paths taking
 * two rows in opposite orders — it is one row, escalated. The fix is to take
 * the strong lock on the cart *first*: a transaction that already holds
 * `FOR UPDATE` on the parent needs nothing raised later, and a second one
 * waits at that lock instead of deadlocking behind it.
 *
 * `OpenCart::for()` runs **outside** the transaction, so two concurrent
 * settlement requests of one buyer (a double-submitted form, a queue retry)
 * resolve the *same* cart row and really do arrive here together. The lock
 * therefore belongs inside the transaction that does the writing, not next
 * to the lookup that found the cart.
 *
 * **Order.** In the three product paths the `ProductVariant` row is locked
 * first and the cart second, which is the order those Actions already had
 * (the variant lock is explicit, the cart lock was the implicit FK one) —
 * `product_variants`, then `carts`, then the `cart_lines` writes.
 * `ExpireCarts` takes its cart lock first and its variant locks after, which
 * is the opposite pair; that inversion predates this trait, the sweep cannot
 * know which variants a cart holds before it has read the cart's lines under
 * the cart's own lock, and #233 deliberately keeps the shop paths as they
 * are. It is recorded in `TODO.md` rather than silently changed in a patch
 * release.
 */
trait LocksTheCartBeforeItsLines
{
    /**
     * The cart row, locked, as the first statement of the transaction that
     * writes this cart's lines. `withoutBrandScope()` because a queue worker
     * and the sweep reach these Actions with no brand in context (ADR-0010),
     * and the key is the cart the caller already resolved.
     *
     * Fails loudly rather than returning null: every caller holds a cart or
     * a `cart_lines` row whose non-nullable in-module foreign key points at
     * one, so a missing row here is a broken database, not a lost race.
     */
    private function lockCart(string $cartId): Cart
    {
        return Cart::withoutBrandScope()->whereKey($cartId)->lockForUpdate()->firstOrFail();
    }
}
