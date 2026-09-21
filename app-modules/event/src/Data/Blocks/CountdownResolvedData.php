<?php

declare(strict_types=1);

namespace Konventix\Event\Data\Blocks;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Server-resolved props for the `countdown` block's Vue component.
 *
 * `targetIso` is a real instant (ADR-0027's `startsAtInstant()` accessor,
 * ISO-8601), unlike {@see EventHeroResolvedData}'s wall-clock strings — the
 * ticking countdown needs an unambiguous point in time to diff against
 * `now()`, not the organizer's promised local time.
 *
 * `slug` follows {@see EventHeroResolvedData}'s rule exactly: set only when
 * the event `isVisibleOnLanding()`, so the block links the event name to a
 * landing page that answers, and stays plain text for a draft whose page
 * would 404 (bugfix round 2026-09-21, Task 17b).
 */
#[TypeScript]
final class CountdownResolvedData extends Data
{
    public function __construct(
        public readonly bool $found,
        public readonly ?string $label,
        public readonly ?string $eventName,
        public readonly ?string $targetIso,
        public readonly ?string $slug,
    ) {}
}
