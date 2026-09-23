<?php

declare(strict_types=1);

namespace Konventix\Competition\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One hit of the console's person search (design §5.2): somebody the §5.1
 * gate lets into the event's competitions, named from this module's own
 * `ParticipantProfile` read model, never from Identity (ADR-0004).
 */
#[TypeScript]
final class PersonOptionData extends Data
{
    public function __construct(
        public readonly string $userId,
        public readonly string $displayName,
        public readonly string $username,
    ) {}
}
