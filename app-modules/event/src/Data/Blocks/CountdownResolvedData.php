<?php

declare(strict_types=1);

namespace Konventix\Event\Data\Blocks;

use Spatie\LaravelData\Data;

/**
 * Server-resolved props for the `countdown` block's Vue component.
 *
 * `targetIso` is a real instant (ADR-0027's `startsAtInstant()` accessor,
 * ISO-8601), unlike {@see EventHeroResolvedData}'s wall-clock strings — the
 * ticking countdown needs an unambiguous point in time to diff against
 * `now()`, not the organizer's promised local time.
 */
final class CountdownResolvedData extends Data
{
    public function __construct(
        public readonly bool $found,
        public readonly ?string $label,
        public readonly ?string $eventName,
        public readonly ?string $targetIso,
    ) {}
}
