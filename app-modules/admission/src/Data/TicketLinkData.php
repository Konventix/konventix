<?php

declare(strict_types=1);

namespace Konventix\Admission\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Admission's contribution to the registrant's own page (design §7): how
 * many tickets the buyer's combined file holds, and the registration id
 * `TicketDownloadController::buyer()` needs to build the download link.
 * Absent entirely — `ticket: null` on the slot's props, `SeatSummaryData`'s
 * own idiom on this same slot — when the registration granted no
 * admissions, or every one it granted has since been revoked.
 */
#[TypeScript]
final class TicketLinkData extends Data
{
    public function __construct(
        public readonly string $registrationId,
        public readonly int $ticketCount,
    ) {}

    /**
     * Named `of*` rather than `from*` (.ai/rules/data.md).
     */
    public static function of(string $registrationId, int $ticketCount): self
    {
        return new self(
            registrationId: $registrationId,
            ticketCount: $ticketCount,
        );
    }
}
