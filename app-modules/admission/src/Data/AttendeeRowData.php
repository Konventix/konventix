<?php

declare(strict_types=1);

namespace Konventix\Admission\Data;

use Carbon\CarbonImmutable;
use Konventix\Admission\Enums\AdmissionState;
use Konventix\Admission\Models\Admission;
use Konventix\Admission\Support\TicketReference;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One row of the attendee list (attendee-list design §4.1, D-ATT-01): one
 * **ticket**, so a person with two tickets is two rows and an unassigned
 * ticket is a row with its buyer. Read only from Admission's own tables and
 * read models (D-ATT-07, ADR-0004).
 *
 * - `email` is the holder's, else the buyer's.
 * - `ticketType` is the registration type as `RegistrationSummary` knows it,
 *   else the admission's own `role` (a crew badge has no registration).
 * - `registrationStatus` is the summary's status as projected — `confirmed`,
 *   `cancelled`, `unconfirmed` for an old-shape backfill, or one of
 *   Registration's own states — and null without a summary; the page labels
 *   it.
 * - `checkIn` is the latest accepted check-in on the current event day
 *   (`Support\LatestAcceptedCheckIn`), or the latest across every day,
 *   marked `lastKnown`, when no day of the calendar covers now.
 * - `owedDoorChecks` is filled only for a row that can check in now; it
 *   carries the labels the check-in dialog asks about, never an entry
 *   condition's assertion (ADR-0066, Consequences).
 */
#[TypeScript]
final class AttendeeRowData extends Data
{
    /**
     * @param  list<DoorCheckData>  $owedDoorChecks
     */
    public function __construct(
        public readonly string $admissionId,
        public readonly string $reference,
        public readonly string $state,
        public readonly ?string $holderUserId,
        public readonly ?string $holderName,
        public readonly ?string $holderUsername,
        public readonly ?string $buyerUserId,
        public readonly ?string $buyerName,
        public readonly ?string $email,
        public readonly string $ticketType,
        public readonly ?string $registrationId,
        public readonly ?string $registrationShortId,
        public readonly ?string $registrationStatus,
        public readonly AttendeeCheckInStateData $checkIn,
        public readonly bool $canCheckIn,
        public readonly bool $canCheckOut,
        #[DataCollectionOf(DoorCheckData::class)]
        public readonly array $owedDoorChecks,
    ) {}

    /**
     * Whether the console offers a check-in: a live ticket that is not in on
     * the current day. Everything else — the day, validity, re-entry — is
     * the core's to decide when the check-in is posted.
     */
    public static function admits(AdmissionState $state, ?string $lastDirection): bool
    {
        return in_array($state, [AdmissionState::Issued, AdmissionState::Assigned], true)
            && $lastDirection !== 'in';
    }

    /**
     * A row of `Queries\AttendeeListQuery`, whose joins put the people, the
     * summary and the latest check-in on the model as extra attributes.
     *
     * @param  list<array{key: string, label: string, documentHint: string|null}>  $owedDoorChecks
     */
    public static function ofListRow(Admission $admission, array $owedDoorChecks, bool $lastKnown = false): self
    {
        $direction = self::nullableString($admission->getAttribute('last_direction'));
        $scannedAt = self::nullableString($admission->getAttribute('last_scanned_at'));
        $holderId = $admission->holder_user_id;
        $registrationId = $admission->registration_id;
        $canCheckIn = self::admits($admission->state, $direction);

        return new self(
            admissionId: $admission->id,
            reference: TicketReference::of($admission->id),
            state: $admission->state->value,
            holderUserId: $holderId,
            holderName: self::nullableString($admission->getAttribute('holder_name')),
            holderUsername: self::nullableString($admission->getAttribute('holder_username')),
            buyerUserId: $admission->buyer_user_id,
            buyerName: self::nullableString($admission->getAttribute('buyer_name')),
            email: self::nullableString($admission->getAttribute('holder_email'))
                ?? self::nullableString($admission->getAttribute('buyer_email')),
            ticketType: self::nullableString($admission->getAttribute('type_name')) ?? $admission->role,
            registrationId: $registrationId,
            registrationShortId: $registrationId === null ? null : strtoupper(substr($registrationId, -8)),
            registrationStatus: self::nullableString($admission->getAttribute('registration_status')),
            checkIn: $direction === null || $scannedAt === null
                ? AttendeeCheckInStateData::never($lastKnown)
                : AttendeeCheckInStateData::ofLatest(
                    $direction,
                    // A plain `timestamp` column read without the model's
                    // cast: the digits are the application's zone (UTC).
                    CarbonImmutable::parse($scannedAt, (string) config('app.timezone'))->toIso8601String(),
                    self::nullableString($admission->getAttribute('last_checkpoint_kind')),
                    $lastKnown,
                ),
            canCheckIn: $canCheckIn,
            canCheckOut: $direction === 'in',
            owedDoorChecks: $canCheckIn
                ? array_map(
                    static fn (array $check): DoorCheckData => new DoorCheckData($check['key'], $check['label'], $check['documentHint']),
                    $owedDoorChecks,
                )
                : [],
        );
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
