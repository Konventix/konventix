<?php

declare(strict_types=1);

namespace Konventix\Admission\Events;

/**
 * An admission was accepted at a door. Emitted by the shared check-in core,
 * `Konventix\Admission\Actions\RecordCheckIn`, which the door reaches
 * through `RecordScan`.
 *
 * **`registrationId` is the reason this event's shape matters beyond
 * Admission.** ADR-0037 makes a checked-in admission block a refund, and
 * D-COM-15 settles that Commerce answers that question from its own read
 * model fed by this event — never by reading Admission's tables. Slice 7's
 * refund flow is blocked until this ships, which is why it leads Slice 5.
 * Removing or renaming this field breaks that contract.
 *
 * It is **nullable** (#333): a crew badge (D-STF-01, `IssueCrewAdmission`)
 * has no registration behind it, and a non-null `string` here made every
 * accepted scan of one a `TypeError` under `strict_types`, at the door and
 * from the console alike. The widening is additive; Commerce's projection
 * skips a null, because a registration that does not exist has no refund to
 * block.
 *
 * `eventDayId` is nullable only because an event may have no day calendar at
 * all; a scan that fell outside a defined calendar is rejected and never
 * reaches this event (D-ADM-04).
 *
 * `conditionsChecked` is what the door decided about this holder's entry
 * conditions (ADR-0066 §6.4, D-ADM-06): a list of `{key, outcome}`, empty
 * for every scan that checks nothing — which is every scan on every event
 * that declares no `document_at_door` condition. Registration's
 * `RecordDoorVerification` is the only listener that reads it, and it reads
 * the key it itself put on the admission at confirmation. A refusal records
 * a fact and nothing more: it never revokes the admission and never reaches
 * Commerce (T-8, ADR-0005).
 *
 * Payload is scalars/ULIDs only (ADR-0004).
 *
 * @phpstan-type ConditionOutcome array{key: string, outcome: string}
 */
final readonly class AdmissionCheckedIn
{
    /**
     * @param  list<ConditionOutcome>  $conditionsChecked
     */
    public function __construct(
        public string $checkInId,
        public string $admissionId,
        public ?string $registrationId,
        public string $eventId,
        public string $brandId,
        public ?string $eventDayId,
        public ?string $checkpointId,
        public string $direction,
        public string $scannedAt,
        public array $conditionsChecked = [],
    ) {}
}
