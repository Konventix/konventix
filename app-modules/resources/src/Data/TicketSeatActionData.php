<?php

declare(strict_types=1);

namespace Konventix\Resources\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Resources' contribution to the ticket page (#292 §6.4): whether this
 * viewer may pick the ticket's seat, which place it sits on now, and the
 * seating mode — an `assign` claim shows the organiser's message instead
 * of a button. Null on the slot's props when the admission has no seat
 * claim or the viewer may not act on it.
 */
#[TypeScript]
final class TicketSeatActionData extends Data
{
    public function __construct(
        public readonly string $admissionId,
        public readonly ?string $placeLabel,
        public readonly string $seatingMode,
        public readonly bool $noFreePlaces,
    ) {}
}
