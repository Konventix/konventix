<?php

declare(strict_types=1);

namespace Konventix\Admission\Data;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The ticket detail page (attendee-list design §4.4, D-ATT-05), built by
 * `Queries\TicketDetailQuery` from Admission's own tables and read models
 * only (ADR-0004).
 *
 * - `ticketType` is the registration type as `RegistrationSummary` knows it,
 *   else the admission's `role` — the attendee list's rule.
 * - The registration fields are null for a crew ticket, which has none.
 * - `doorChecks` is every stamped `entry_conditions` entry with its latest
 *   recorded outcome; `history` is every check-in row, oldest first.
 * - `badgeUrl` points at the existing `admin.admissions.badge` and is set
 *   only for a viewer holding `admission.print-badge`.
 * - `canCheckIn`, `canCheckOut` and `owedDoorChecks` follow the list's own
 *   rule (`AttendeeRowData::admits()`, `Support\LatestAcceptedCheckIn` on
 *   the current event day, `Support\OwedDoorChecks`).
 */
#[TypeScript]
final class TicketDetailData extends Data
{
    /**
     * @param  list<TicketDoorCheckData>  $doorChecks
     * @param  list<CheckInHistoryRowData>  $history
     * @param  list<DoorCheckData>  $owedDoorChecks
     */
    public function __construct(
        public readonly string $admissionId,
        public readonly string $reference,
        public readonly string $state,
        public readonly string $role,
        public readonly ?string $validFrom,
        public readonly ?string $validUntil,
        public readonly string $eventId,
        public readonly string $eventName,
        public readonly ?string $holderUserId,
        public readonly ?string $holderName,
        public readonly ?string $holderUsername,
        public readonly ?string $buyerUserId,
        public readonly ?string $buyerName,
        public readonly ?string $buyerUsername,
        public readonly string $ticketType,
        public readonly ?string $registrationId,
        public readonly ?string $registrationShortId,
        public readonly ?string $registrationTypeName,
        public readonly ?string $registrationStatus,
        #[DataCollectionOf(TicketDoorCheckData::class)]
        public readonly array $doorChecks,
        #[DataCollectionOf(CheckInHistoryRowData::class)]
        public readonly array $history,
        public readonly ?string $badgeUrl,
        public readonly bool $canCheckIn,
        public readonly bool $canCheckOut,
        #[DataCollectionOf(DoorCheckData::class)]
        public readonly array $owedDoorChecks,
    ) {}
}
