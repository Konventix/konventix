<?php

declare(strict_types=1);

namespace Konventix\Competition\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One name on the public "interested to play" list (design §5.3), with the
 * line the person wrote. No account ULID: the list is for finding each
 * other, not for addressing anybody.
 */
#[TypeScript]
final class InterestedPersonData extends Data
{
    public function __construct(
        public readonly string $displayName,
        public readonly ?string $note,
    ) {}
}
