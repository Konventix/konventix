<?php

declare(strict_types=1);

namespace Konventix\Commerce\Actions;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Konventix\Commerce\Enums\CartLineKind;
use Konventix\Commerce\Exceptions\SaleWindowClosedException;
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
 * #218 (#216 draft i): the request also carries the type's sales window as
 * two nullable ISO-8601 instants. A window that is not open right now is
 * refused outright — `SubmitRegistration` already refuses such a submission,
 * so reaching here means a replay of a stale request, and creating a line
 * the checkout would refuse anyway helps nobody. An open window is
 * snapshotted onto the line: Commerce may not read Registration's table
 * (ADR-0004), so this is its only chance to keep what it must re-check when
 * the payer finally clicks through.
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
        $this->guardSaleWindow($registrationId, $saleStartsAt, $saleEndsAt);

        $cart = OpenCart::for($brandId, $registrantUserId);

        $existing = CartLine::query()
            ->where('cart_id', $cart->getKey())
            ->where('kind', CartLineKind::Registration)
            ->where('request_key', $requestKey)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return DB::transaction(function () use ($cart, $registrationId, $requestKey, $lines, $saleStartsAt, $saleEndsAt): CartLine {
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
                'sale_starts_at' => $saleStartsAt,
                'sale_ends_at' => $saleEndsAt,
            ]);
            $cartLine->brand_id = $cart->brand_id;
            $cartLine->save();

            $cart->touchExpiry();

            return $cartLine;
        });
    }

    /**
     * Start inclusive, end exclusive, null on either side meaning no bound —
     * the same reading `RegistrationType::isOnSale()` and `CartLine::isOnSale()`
     * apply, so the three never disagree about a boundary second.
     */
    private function guardSaleWindow(string $registrationId, ?string $saleStartsAt, ?string $saleEndsAt): void
    {
        $now = now();

        $open = ($saleStartsAt === null || ! $now->lessThan(Carbon::parse($saleStartsAt)))
            && ($saleEndsAt === null || $now->lessThan(Carbon::parse($saleEndsAt)));

        if (! $open) {
            throw SaleWindowClosedException::forRegistration($registrationId, $saleStartsAt, $saleEndsAt);
        }
    }
}
