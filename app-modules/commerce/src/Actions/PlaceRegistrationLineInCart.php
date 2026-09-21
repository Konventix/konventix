<?php

declare(strict_types=1);

namespace Konventix\Commerce\Actions;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Konventix\Commerce\Enums\CartLineKind;
use Konventix\Commerce\Events\RegistrationLinePlacedInCart;
use Konventix\Commerce\Exceptions\SaleWindowClosedException;
use Konventix\Commerce\Models\CartLine;

/**
 * A registration joins the cart by submitting first (ADR-0047 point 3): an
 * `instant` settlement request lands in the registrant's open cart as one
 * line instead of placing an order — the registration row already exists
 * and already holds its own quota, only where the money settles changes.
 *
 * A registration always settles as one line, however many priced lines its
 * settlement request carried: `label` is the first line's label,
 * `tax_class` the first line's class — the cart shows "your registration",
 * not an itemised breakdown Registration never asked for.
 *
 * The *quantity* comes from the request (bugfix round 2026-09-21, Task 19):
 * a registration may now buy N units, and `unit_amount_net` stays the price
 * of one so the cart reads "3 × 50,00" and the totals multiply the way they
 * do for a product line. That only works while every request line agrees on
 * the quantity, which is the shape Registration sends — one line at the
 * registration's own quantity, or an amendment's lines. Where they disagree
 * the line falls back to the old shape, quantity one at the summed total:
 * the money is identical either way, and inventing a per-unit price by
 * dividing would not be.
 *
 * `ChangeCartLineQuantity` still refuses this line. The quantity is the
 * registration's own fact, fixed at submission, and the registration holds
 * exactly that many quota units — changing it in the cart would move the
 * money without moving the hold.
 *
 * #218 (#216 draft i, fix round 1): the request may carry the type's sales
 * window as two nullable ISO-8601 instants, and **this is the only place
 * Commerce enforces it**. Null means "not window-bound", which is what
 * Registration sends for a waitlist conversion, a confirmation after
 * approval and a priced amendment — it is the authority on who may register,
 * and this Action never second-guesses it. A window that is present and not
 * open is a fresh sale arriving too early or too late, and it is refused:
 * the registrant is at the form right now and gets told.
 *
 * The refusal sits *after* the idempotency lookup on purpose. A replay of a
 * request whose line already exists returns that line whatever the clock
 * says; the line was placed while the sale was open and refusing it now would
 * contradict the promise below. That ordering also puts the refusal below
 * `OpenCart::for()`, which may create the cart row a refused request then
 * never fills (fix round 2, review finding N5): the lookup needs the cart, so
 * the two cannot both come first. It is harmless as called — the throw
 * propagates into the emitting Action's own `DB::transaction`, which rolls
 * the cart back with everything else — and only a caller outside a
 * transaction could leave an empty cart behind. Reordering would mean
 * splitting `OpenCart::for()` into a find and a create, which is a larger
 * change than an unreachable stray row is worth.
 *
 * The window is also snapshotted onto the line, normalized to UTC first
 * (Eloquent's `datetime` cast stores an offset-bearing string by its
 * wall-clock digits). It is kept for audit only — nothing renders it, and
 * the checkout does not re-check it; see `PlaceOrderFromCart`. The window
 * this Action itself enforces is the one on the *event*, read off the
 * unsaved probe below, never off a persisted column.
 *
 * Announces itself with `RegistrationLinePlacedInCart` on the placement
 * that actually creates the line — never on the idempotent replay, which
 * has nothing new to tell anyone. Registration listens for it so its
 * confirmation mail can name the cart and the hold's deadline (#210);
 * ADR-0004 keeps that a scalar payload rather than a Commerce table read.
 *
 * Idempotent on `(cart, registration, request_key)`: the partial unique
 * index `cart_lines_request_key_unique` is `(cart_id, kind, request_key)`,
 * so a replayed settlement request (a queued retry) finds the line it
 * already created rather than duplicating it — the same discipline
 * `PlaceOrderFromSettlementRequest` already applies to orders.
 */
final class PlaceRegistrationLineInCart
{
    /**
     * @param  list<array{label: string, quantity: int, unit_amount_net: numeric-string, tax_class: string}>  $lines
     */
    public function handle(
        string $brandId,
        string $registrantUserId,
        string $registrationId,
        string $requestKey,
        array $lines,
        ?string $saleStartsAt = null,
        ?string $saleEndsAt = null,
    ): CartLine {
        $cart = OpenCart::for($brandId, $registrantUserId);

        $existing = CartLine::query()
            ->where('cart_id', $cart->getKey())
            ->where('kind', CartLineKind::Registration)
            ->where('request_key', $requestKey)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $saleStartsAt = $saleStartsAt === null ? null : Carbon::parse($saleStartsAt)->utc();
        $saleEndsAt = $saleEndsAt === null ? null : Carbon::parse($saleEndsAt)->utc();

        $this->guardSaleWindow($registrationId, $saleStartsAt, $saleEndsAt);

        $cartLine = DB::transaction(function () use ($cart, $registrationId, $requestKey, $lines, $saleStartsAt, $saleEndsAt): CartLine {
            $first = $lines[0];

            // One quantity for the whole line, and only when every request
            // line already agrees on it — otherwise the line keeps the old
            // shape (see this class's docblock). `$unitNet` is what the
            // cart multiplies by `$quantity`, so both branches come to the
            // same total.
            $uniform = count(array_unique(array_column($lines, 'quantity'))) === 1;
            $quantity = $uniform ? $first['quantity'] : 1;

            $unitNet = '0.00';
            foreach ($lines as $line) {
                $unitNet = bcadd(
                    $unitNet,
                    $uniform ? $line['unit_amount_net'] : bcmul($line['unit_amount_net'], (string) $line['quantity'], 2),
                    2,
                );
            }

            $cartLine = new CartLine([
                'cart_id' => $cart->getKey(),
                'kind' => CartLineKind::Registration,
                'ref_id' => $registrationId,
                'request_key' => $requestKey,
                'label' => $first['label'],
                'quantity' => $quantity,
                'unit_amount_net' => $unitNet,
                'tax_class' => $first['tax_class'],
                'position' => $cart->lines()->count(),
                'sale_starts_at' => $saleStartsAt,
                'sale_ends_at' => $saleEndsAt,
            ]);
            $cartLine->brand_id = $cart->brand_id;
            $cartLine->save();

            $cart->touchExpiry();

            return $cartLine;
        });

        event(new RegistrationLinePlacedInCart(
            cartId: $cart->getKey(),
            brandId: $cart->brand_id,
            registrationId: $registrationId,
            requestKey: $requestKey,
            // The deadline `touchExpiry()` just wrote — the hold the
            // registrant actually has, not this Action's own reading of
            // what `LIFETIME_MINUTES` means.
            holdExpiresAt: $cart->expires_at->toIso8601String(),
        ));

        return $cartLine;
    }

    /**
     * The boundary rule is asked of an unsaved `CartLine` rather than written
     * out a second time (fix round 1, review finding 4): Commerce owns exactly
     * one window predicate, `CartLine::isOnSale()`, and it agrees with
     * `RegistrationType::isOnSale()` across the module boundary — start
     * inclusive, end exclusive, null = open. Three hand-written copies of a
     * boundary rule in a money path drift; one does not.
     */
    private function guardSaleWindow(string $registrationId, ?CarbonInterface $saleStartsAt, ?CarbonInterface $saleEndsAt): void
    {
        $probe = new CartLine(['sale_starts_at' => $saleStartsAt, 'sale_ends_at' => $saleEndsAt]);

        if (! $probe->isOnSale()) {
            throw SaleWindowClosedException::forRegistration(
                $registrationId,
                $saleStartsAt?->toIso8601String(),
                $saleEndsAt?->toIso8601String(),
            );
        }
    }
}
