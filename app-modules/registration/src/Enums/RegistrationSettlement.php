<?php

declare(strict_types=1);

namespace Konventix\Registration\Enums;

/**
 * How a `RegistrationType`'s registrations are settled (ADR-0005, SPEC.md
 * "Owns" — `RegistrationType`).
 *
 * `Free` never touches Commerce at all (ADR-0026): no order, no order lines,
 * no events. `Instant` and `Invoice` emit `RegistrationSettlementRequested`
 * (see `requestsSettlement()`); `External` records that money moves entirely
 * outside the platform, so it produces no Commerce order either.
 */
enum RegistrationSettlement: string
{
    case Free = 'free';
    case Instant = 'instant';
    case Invoice = 'invoice';
    case External = 'external';

    /**
     * Whether registrations of this settlement emit
     * `RegistrationSettlementRequested` so Commerce creates an order.
     * `Free` never does (ADR-0026). `External` doesn't either: money moves
     * outside the platform entirely — door payment, when it comes, is a
     * Commerce point of sale (ADR-0018), not this settlement axis.
     */
    public function requestsSettlement(): bool
    {
        return match ($this) {
            self::Instant, self::Invoice => true,
            self::Free, self::External => false,
        };
    }
}
