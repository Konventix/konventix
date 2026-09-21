<?php

declare(strict_types=1);

namespace Konventix\Registration\Data;

use Konventix\Registration\Blocks\TicketsForEventBlock;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Nullable;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Attributes\Validation\StringType;
use Spatie\LaravelData\Attributes\Validation\Ulid;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Stored payload of the `tickets-for-event` static-page block: the event's
 * ULID and an optional heading. The offers themselves are looked up at
 * render time by {@see TicketsForEventBlock::resolve()} into
 * {@see TicketsForEventResolvedData} — a page never stores a price, and an
 * event ULID is the only foreign reference a block may keep (ADR-0004).
 */
#[TypeScript]
final class TicketsForEventBlockData extends Data
{
    public function __construct(
        #[Required, Ulid]
        public readonly string $eventId,

        #[Nullable, StringType, Max(255)]
        public readonly ?string $heading = null,
    ) {}
}
