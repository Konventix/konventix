<?php

declare(strict_types=1);

namespace Konventix\Admission\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One row of a ticket's check-in history on the ticket detail page
 * (attendee-list design §4.4): when, which way, accepted or rejected and why
 * (the console sentence of the reason code), at which checkpoint, and who —
 * the door device for a scan, the acting admin for a console check-in.
 * `source` tells the two apart the way the attendee list does, off the
 * checkpoint's `kind`.
 */
#[TypeScript]
final class CheckInHistoryRowData extends Data
{
    public function __construct(
        public readonly string $id,
        public readonly string $scannedAt,
        #[LiteralTypeScriptType("'in' | 'out'")]
        public readonly string $direction,
        #[LiteralTypeScriptType("'accepted' | 'rejected'")]
        public readonly string $result,
        public readonly ?string $reason,
        #[LiteralTypeScriptType("'door' | 'console'")]
        public readonly string $source,
        public readonly ?string $checkpointName,
        public readonly ?string $deviceLabel,
        public readonly ?string $actorUserId,
        public readonly ?string $actorName,
    ) {}
}
