<?php

declare(strict_types=1);

namespace Konventix\Event\Data\Blocks;

use Konventix\Event\Blocks\EventHeroBlock;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Attributes\Validation\Ulid;
use Spatie\LaravelData\Data;

/**
 * Stored payload of the `event-hero` static-page block: just the event's
 * ULID. Everything the Vue component renders — name, wall-clock schedule,
 * venue city — is computed server-side by
 * {@see EventHeroBlock::resolve()} into
 * {@see EventHeroResolvedData}, never handed to the frontend as a raw model.
 */
final class EventHeroBlockData extends Data
{
    public function __construct(
        #[Required, Ulid]
        public readonly string $eventId,
    ) {}
}
