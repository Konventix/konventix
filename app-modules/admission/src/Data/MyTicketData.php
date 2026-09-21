<?php

declare(strict_types=1);

namespace Konventix\Admission\Data;

use Konventix\Admission\Models\Admission;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One of the holder's own admissions, as `/my/tickets` shows it (design
 * §4.1, §7). `eventName` and `eventSlug` come from this module's own
 * `EventSummary` read model, never from Event's tables (ADR-0004); the
 * name falls back to the ULID the same way `MyRegistrationData::ofModel()`
 * does — an admission whose event summary has not arrived yet is still
 * the holder's, and still has to render — and the slug, when present,
 * links the row to the event's page.
 *
 * The holder is a handle (ADR-0065): `@username` on the page, never the
 * display name, which stays with the ticket PDF and the console.
 */
#[TypeScript]
final class MyTicketData extends Data
{
    public function __construct(
        public readonly string $id,
        public readonly string $eventId,
        public readonly string $eventName,
        public readonly ?string $eventSlug,
        public readonly string $role,
        public readonly string $state,
        /** Who holds it, for the "tickets you manage" list (#292); null while unassigned or until the projection knows the handle. */
        public readonly ?string $holderUsername = null,
    ) {}

    /**
     * Named `of*` rather than `from*` (.ai/rules/data.md).
     */
    public static function ofModel(Admission $admission, ?string $eventName, ?string $eventSlug = null, ?string $holderUsername = null): self
    {
        return new self(
            id: $admission->getKey(),
            eventId: $admission->event_id,
            eventName: $eventName ?? $admission->event_id,
            eventSlug: $eventSlug,
            role: $admission->role,
            state: $admission->state->value,
            holderUsername: $holderUsername,
        );
    }
}
