<?php

declare(strict_types=1);

namespace Konventix\Commerce\Events;

/**
 * `CartLineExpired`'s sibling on creation: Commerce has taken a
 * registration's `instant` settlement into the registrant's cart rather
 * than placing an order for it (ADR-0047 point 3), and the hold that comes
 * with it runs out at `holdExpiresAt`.
 *
 * Registration is the module that needs to know (#210): with the shop on,
 * for every gate but `on_payment` the registrant is not standing in the
 * shop when this happens, so the lifecycle mail Registration sends is the
 * only push that can name where the money is paid — and Registration must
 * not read `cart_lines` to find out (ADR-0004). It stores the deadline on
 * its own row instead, the way `invoiced_at` already comes from
 * `InvoiceIssued`.
 *
 * Payload is scalars/ULIDs only (ADR-0004) — never an Eloquent model. The
 * cart's URL is deliberately absent: the recipient-facing, brand-absolute
 * form of it is Registration's own `BrandUrl` work, behind the same
 * `Route::has('commerce.cart.show')` guard the registration controller
 * uses, and a link built here would be built in whatever context the
 * settlement request happened to run in.
 */
final readonly class RegistrationLinePlacedInCart
{
    public function __construct(
        public string $cartId,
        public string $brandId,
        public string $registrationId,
        public ?string $requestKey,
        public string $holdExpiresAt,
    ) {}
}
