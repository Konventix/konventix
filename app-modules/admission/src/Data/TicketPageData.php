<?php

declare(strict_types=1);

namespace Konventix\Admission\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Everything the ticket page shows about one admission (#292 §4.4): the
 * event band from this module's own event summary, the holder from its
 * people read model, the seat from `admission_seats`, and the scan code
 * as inline SVG of the admission's *current* KVX1 token (ADR-0061 — the
 * same credential the PDF prints, never a second one).
 *
 * Dates arrive already formatted: the event's times are the organizer's
 * wall clock (ADR-0027), the validity range the operator's format, both
 * exactly as the PDF prints them.
 */
#[TypeScript]
final class TicketPageData extends Data
{
    public function __construct(
        public readonly string $id,
        public readonly string $eventId,
        public readonly ?string $eventName,
        public readonly ?string $eventSlug,
        public readonly ?string $eventWhen,
        public readonly ?string $venueLabel,
        public readonly ?string $bannerUrl,
        public readonly ?string $bannerAlt,
        public readonly string $role,
        public readonly string $state,
        public readonly string $validRange,
        public readonly string $holderName,
        public readonly bool $isHolder,
        public readonly bool $isManager,
        public readonly bool $checkedIn,
        public readonly string $qrSvg,
        public readonly string $doorCode,
        public readonly ?TicketSeatData $seat,
        public readonly ?string $registrationId,
    ) {}
}
