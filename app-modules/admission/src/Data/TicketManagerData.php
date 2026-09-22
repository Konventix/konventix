<?php

declare(strict_types=1);

namespace Konventix\Admission\Data;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
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
     * `admissions` is a typed array rather than a `DataCollection`, the way
     * every other `#[TypeScript]` Data class types its rows
     * (`ShoutboxRoomData::$presence`): the transformer cannot read a
     * `DataCollection<int, X>` annotation and wrote `undefined<number, X>`
     * into `generated.d.ts`, which prettier then refused to parse.
     *
     * @param  array<int, ManagedAdmissionData>  $admissions
     */
    public function __construct(
        public readonly string $registrationId,
        public readonly int $ticketCount,
        public readonly bool $holdsOne,
        /** @var array<int, ManagedAdmissionData> */
        #[DataCollectionOf(ManagedAdmissionData::class)]
        public readonly array $admissions,
    ) {}
}
