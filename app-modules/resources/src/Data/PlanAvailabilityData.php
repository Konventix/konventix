<?php

declare(strict_types=1);

namespace Konventix\Resources\Data;

use Konventix\Resources\Enums\PlaceState;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The picker's view of every place of an event (design §7.4), keyed by
 * element ULID — never by ref. Computed from `resource_bookings`, never from
 * the document (ADR-0060 point 4); polled every 15 s while the picker is
 * open. `generatedAt` is an ISO-8601 instant.
 */
#[TypeScript]
final class PlanAvailabilityData extends Data
{
    /**
     * @param  array<string, PlaceState>  $places
     */
    public function __construct(
        public readonly string $eventId,
        public readonly string $generatedAt,

        /** @var array<string, PlaceState> */
        public readonly array $places,
    ) {}
}
