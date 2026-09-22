<?php

declare(strict_types=1);

namespace Konventix\Competition\Data;

use Konventix\Competition\Enums\CompetitionStatus;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One row of the competition console's list (design §9: "per event: list
 * with status, entrants/limit, next action"). Generated into TypeScript
 * rather than hand-written on the page (ADR-0009).
 *
 * `entrants` counts every entrant holding a slot, `incomplete` rosters
 * included (§5.4) — it is the number the participant limit is measured
 * against, so hiding them would tell the organizer there is room that is
 * already taken.
 *
 * `nextStatus` is the one transition the list offers as a button: the
 * lifecycle's forward arrow from where this competition stands (§4). Null
 * in a terminal state, and never the whole set — cancelling and unpublishing
 * live on the competition's own screen, where the consequence is spelled
 * out.
 */
#[TypeScript]
final class CompetitionRowData extends Data
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $slug,
        public readonly CompetitionStatus $status,
        public readonly string $eventName,
        public readonly string $disciplineName,
        public readonly string $stageFormat,
        public readonly int $entrants,
        public readonly ?int $maxEntrants,
        public readonly ?string $startsAt,
        public readonly ?CompetitionStatus $nextStatus,
        public readonly ?string $nextLabel,
    ) {}
}
