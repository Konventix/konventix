<?php

declare(strict_types=1);

namespace Konventix\Commerce\Data;

use Konventix\Commerce\WaitingRoom\WaitingRoomStore;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * What the waiting room's poll reads every five seconds
 * (`WaitingRoomController::status()`, ADR-0047 point 5, Task 13):
 * `position` is null while the ticket has been admitted (or never queued
 * at all), `redirectTo` is the ticket's own intended destination once
 * admitted (falling back to `/shop` for the rare ticket whose stored URL
 * has expired out of the store already) and stays null while still
 * queued — the page only ever navigates away once this says to.
 */
#[TypeScript]
final class WaitingRoomStatusData extends Data
{
    public function __construct(
        public readonly ?int $position,
        public readonly bool $admitted,
        public readonly ?string $redirectTo,
        public readonly int $depth,
    ) {}

    /**
     * `of*`, never `from*` (.ai/rules/data.md). `$ticketId` is null for a
     * visitor with no valid ticket cookie at all — the same "nothing to
     * report" shape the controller already returned by hand.
     */
    public static function ofStore(WaitingRoomStore $store, string $brandId, ?string $ticketId): self
    {
        $depth = $store->depth($brandId);

        if ($ticketId === null) {
            return new self(
                position: null,
                admitted: false,
                redirectTo: null,
                depth: $depth,
            );
        }

        $admitted = $store->isAdmitted($brandId, $ticketId);

        return new self(
            position: $store->positionOf($brandId, $ticketId),
            admitted: $admitted,
            redirectTo: $admitted
                ? ($store->intendedUrl($brandId, $ticketId) ?? route('commerce.shop.index'))
                : null,
            depth: $depth,
        );
    }
}
