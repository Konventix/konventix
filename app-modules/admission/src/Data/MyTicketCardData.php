<?php

declare(strict_types=1);

namespace Konventix\Admission\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The live hub's ticket card (D-HUB-05, design §5.2): what the door needs
 * from the participant, without the ticket page around it.
 *
 * `#[TypeScript]` because the card's Vue component types its props from
 * this shape — `HubCardData::$payload` is spelled `Record<string, unknown>`
 * on the wire (the transformer can name no type for the abstract `Data`),
 * so the only typed contract between this class and the component is this
 * generated interface.
 *
 * `qrSvg` is server-rendered markup of the admission's *current* token
 * (ADR-0061), the same string the ticket page renders — opening the hub
 * must not change a code already printed (`.ai/rules/tickets.md`).
 */
#[TypeScript]
final class MyTicketCardData extends Data
{
    public function __construct(
        public readonly string $admissionId,
        public readonly ?string $eventName,
        public readonly string $role,
        public readonly string $qrSvg,
        public readonly string $ticketUrl,
        public readonly string $reentryHint,
    ) {}
}
