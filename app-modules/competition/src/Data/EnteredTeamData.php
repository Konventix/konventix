<?php

declare(strict_types=1);

namespace Konventix\Competition\Data;

use Konventix\Competition\Enums\EntrantState;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * An entered team and its roster on the competition page (design §10).
 * Member names come from `ParticipantProfile`, never from Identity.
 */
#[TypeScript]
final class EnteredTeamData extends Data
{
    /**
     * @param  list<string>  $members
     */
    public function __construct(
        public readonly string $teamId,
        public readonly string $name,
        public readonly EntrantState $state,
        /** @var list<string> display names, oldest member first */
        public readonly array $members,
    ) {}
}
