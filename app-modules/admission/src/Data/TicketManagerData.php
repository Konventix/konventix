<?php

declare(strict_types=1);

namespace Konventix\Admission\Data;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Admission's contribution to the registrant's own page (#292 §4.4, #16):
 * the download of the buyer's combined file, and one row per admission the
 * registration granted with the actions its state allows. Absent entirely
 * — null on the slot's props, `SeatSummaryData`'s idiom — when the
 * registration granted no admissions or every one has been revoked.
 */
#[TypeScript]
final class TicketManagerData extends Data
{
    /**
     * @param  DataCollection<int, ManagedAdmissionData>  $admissions
     */
    public function __construct(
        public readonly string $registrationId,
        public readonly int $ticketCount,
        public readonly bool $holdsOne,
        public readonly DataCollection $admissions,
    ) {}
}
