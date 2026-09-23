<?php

declare(strict_types=1);

namespace Konventix\Admission\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One entry of a ticket's stamped `entry_conditions` on the ticket detail
 * page (attendee-list design §4.4, ADR-0066 §6.4), with the latest outcome
 * any accepted check-in recorded for it, or null when none has. Only a
 * `document_at_door` condition is ever answered at the door or the console;
 * the others are listed so the page shows what the ticket carries.
 */
#[TypeScript]
final class TicketDoorCheckData extends Data
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $verification,
        public readonly ?string $documentHint,
        #[LiteralTypeScriptType("'confirmed' | 'refused' | null")]
        public readonly ?string $outcome,
    ) {}
}
