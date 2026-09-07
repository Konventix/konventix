<?php

declare(strict_types=1);

namespace Konventix\Commerce\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Commerce\Enums\CartLineKind;
use Konventix\Commerce\Events\RegistrationLinePlacedInCart;
use Konventix\Commerce\Models\CartLine;

/**
 * A registration joins the cart by submitting first (ADR-0047 point 3): an
 * `instant` settlement request lands in the registrant's open cart as one
 * line instead of placing an order — the registration row already exists
 * and already holds its own quota, only where the money settles changes.
 *
 * A registration always settles as one line, however many priced lines its
 * settlement request carried: `unit_amount_net` is the sum of every
 * request line's own net (`unit_amount_net * quantity`), `label` is the
 * first line's label, `tax_class` the first line's class — the cart shows
 * "your registration", not an itemised breakdown Registration never asked
 * for.
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

        $cartLine = DB::transaction(function () use ($cart, $registrationId, $requestKey, $lines): CartLine {
            $first = $lines[0];

            $totalNet = '0.00';
            foreach ($lines as $line) {
                $totalNet = bcadd($totalNet, bcmul($line['unit_amount_net'], (string) $line['quantity'], 2), 2);
            }

            $cartLine = new CartLine([
                'cart_id' => $cart->getKey(),
                'kind' => CartLineKind::Registration,
                'ref_id' => $registrationId,
                'request_key' => $requestKey,
                'label' => $first['label'],
                'quantity' => 1,
                'unit_amount_net' => $totalNet,
                'tax_class' => $first['tax_class'],
                'position' => $cart->lines()->count(),
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
}
