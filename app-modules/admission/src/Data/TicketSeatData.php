<?php

declare(strict_types=1);

namespace Konventix\Admission\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The place an admission sits on (#292 §4.4), as this module's
 * `admission_seats` read model knows it — projected off Resources' booking
 * events, never read from its tables (ADR-0004).
 */
#[TypeScript]
final class TicketSeatData extends Data
{
    public function __construct(
        public readonly string $bookingId,
        public readonly ?string $placeRef,
        public readonly string $placeLabel,
    ) {}
}
