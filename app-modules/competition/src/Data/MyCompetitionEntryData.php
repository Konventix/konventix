<?php

declare(strict_types=1);

namespace Konventix\Competition\Data;

use Konventix\Competition\Enums\CompetitionStatus;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One row of the hub's *Meine Competitions* card (design §10a): a
 * competition the viewer's team is entered in at the running event.
 *
 * `checkedIn` means something only where `checkInRequired` is true — a
 * competition without check-in has nothing to be checked in to, and the
 * card says nothing about it. `competitionUrl` is empty for an event whose
 * slug this module has not been told, which has no public page to link.
 */
#[TypeScript]
final class MyCompetitionEntryData extends Data
{
    public function __construct(
        public readonly string $competitionName,
        public readonly CompetitionStatus $status,
        public readonly bool $checkInRequired,
        public readonly bool $checkedIn,
        public readonly string $competitionUrl,
    ) {}
}
