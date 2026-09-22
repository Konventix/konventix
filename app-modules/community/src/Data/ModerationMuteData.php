<?php

declare(strict_types=1);

namespace Konventix\Community\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A person currently silenced in the room (spec §4.3, D-COM-08) — the
 * strongest mute in force for them. `expiresAt` null is "for the rest of
 * the event".
 */
#[TypeScript]
final class ModerationMuteData extends Data
{
    public function __construct(
        public readonly string $userId,
        public readonly ?string $displayName,
        public readonly string $reason,
        public readonly ?string $expiresAt,
        public readonly string $mutedAt,
    ) {}
}
