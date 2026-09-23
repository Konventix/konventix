<?php

declare(strict_types=1);

namespace Konventix\Admission\Data;

use Konventix\Admission\Enums\CheckpointKind;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A ticket's latest accepted check-in, as the attendee list shows it
 * (attendee-list design §4.1): `in`, `out`, or never (`direction` null), when
 * (an ISO instant the page formats in the reader's zone), and whether it came
 * from a door device or the console — read off the checkpoint's `kind`, so a
 * scan at a door (or an old row with no checkpoint) reads `door`.
 */
#[TypeScript]
final class AttendeeCheckInStateData extends Data
{
    /**
     * @param  'in'|'out'|null  $direction
     * @param  'door'|'console'|null  $source
     */
    public function __construct(
        #[LiteralTypeScriptType("'in' | 'out' | null")]
        public readonly ?string $direction,
        public readonly ?string $at,
        #[LiteralTypeScriptType("'door' | 'console' | null")]
        public readonly ?string $source,
    ) {}

    public static function never(): self
    {
        return new self(null, null, null);
    }

    public static function ofLatest(string $direction, string $at, ?string $checkpointKind): self
    {
        return new self(
            direction: $direction === 'out' ? 'out' : 'in',
            at: $at,
            source: $checkpointKind === CheckpointKind::Console->value ? 'console' : 'door',
        );
    }
}
