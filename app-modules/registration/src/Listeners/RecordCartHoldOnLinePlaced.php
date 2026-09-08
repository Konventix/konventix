<?php

declare(strict_types=1);

namespace Konventix\Registration\Listeners;

use Illuminate\Support\Carbon;
use Konventix\Commerce\Events\RegistrationLinePlacedInCart;
use Konventix\Registration\Models\Registration;

/**
 * #210 acceptance point 5: with `commerce:shop` on, an `instant`
 * settlement becomes a cart line rather than an order (ADR-0047 point 3),
 * and Registration cannot read `cart_lines` to find that out (ADR-0004).
 * It learns it from Commerce's `RegistrationLinePlacedInCart` and stamps
 * `cart_hold_expires_at` on its own row — the same shape
 * `MarkInvoicedOnInvoiceIssued` uses for `invoiced_at`.
 *
 * The stamp is what `RegistrationMailer::cartFields()` reads, so the
 * confirmation mail can name the cart and say how long the reservation
 * lasts. The latest placement wins: a re-priced amendment adds a second
 * line and pushes the hold out, and the payable deadline is the last one.
 */
final class RecordCartHoldOnLinePlaced
{
    public function handle(RegistrationLinePlacedInCart $event): void
    {
        Registration::withoutBrandScope()
            ->where('brand_id', $event->brandId)
            ->whereKey($event->registrationId)
            ->update(['cart_hold_expires_at' => Carbon::parse($event->holdExpiresAt)]);
    }
}
