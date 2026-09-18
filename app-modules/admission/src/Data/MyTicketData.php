<?php

declare(strict_types=1);

namespace Konventix\Admission\Data;

use Konventix\Admission\Models\Admission;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One of the holder's own admissions, as `/my/tickets` shows it (design
 * §4.1, §7). `eventName` comes from this module's own `EventSummary` read
 * model, never from Event's tables (ADR-0004), and falls back to the ULID
 * the same way `MyRegistrationData::ofModel()` does — an admission whose
 * event summary has not arrived yet is still the holder's, and still has
 * to render.
 */
#[TypeScript]
final class MyTicketData extends Data
{
    public function __construct(
        public readonly string $id,
        public readonly string $eventId,
        public readonly string $eventName,
        public readonly string $role,
        public readonly string $state,
        /** Who holds it, for the "tickets you manage" list (#292); null while unassigned. */
        public readonly ?string $holderName = null,
    ) {}

    /**
     * Named `of*` rather than `from*` (.ai/rules/data.md).
     */
    public static function ofModel(Admission $admission, ?string $eventName, ?string $holderName = null): self
    {
        return new self(
            id: $admission->getKey(),
            eventId: $admission->event_id,
            eventName: $eventName ?? $admission->event_id,
            role: $admission->role,
            state: $admission->state->value,
            holderName: $holderName,
        );
    }
}
