<?php

declare(strict_types=1);

namespace Konventix\Admission\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Admission\Enums\AdmissionState;
use Konventix\Admission\Events\AdmissionIssued;
use Konventix\Admission\Models\Admission;

/**
 * Issues one `Admission` per granted admission entry on a confirmed
 * registration — `{kind: 'admission'} × quantity` — using nothing but scalar
 * arguments (SPEC.md "Consumes" — `RegistrationConfirmed`, TODO.md "Listen to
 * RegistrationConfirmed and issue one admission per granted admission").
 *
 * Deliberately takes primitives, not the Registration event object: the
 * thin `IssueAdmissionsOnRegistrationConfirmed` listener unpacks the event
 * and calls this, so this class — and everything it touches — never needs to
 * know the event class exists. That is what makes "uses only the event
 * payload" a structural property instead of a convention.
 *
 * Idempotent and replayable (TODO.md), keyed on the *issuing occasion*
 * rather than the registration: `$amendmentId` is null for the original
 * confirmation and the amendment's ULID for each delta (ADR-0036). A plain
 * "does this registration have any admissions?" guard was sufficient while a
 * registration received grants exactly once, but it silently swallows every
 * amendment — so the guard is now per occasion, and each admission records
 * which one issued it. `withoutBrandScope()` is the named escape hatch
 * (ADR-0010): the check must hold regardless of which brand is ambient when
 * a replay happens, so it is scoped explicitly from the payload's own
 * `brandId` instead.
 *
 * `'admission'` is written as a literal rather than importing
 * `Konventix\Registration\Enums\GrantKind`: this module does not import
 * Registration at all (see
 * `tests/RegistrationCommerceImportBoundaryTest.php`), so the string is the
 * contract, not the enum.
 *
 * `$registrantUserId` is stored as `buyer_user_id` on every admission this
 * occasion issues (design doc §13, 2026-09-11 amendment "a
 * registration-derived admission is anonymous today"). It is a different
 * fact from `holder_user_id`, which stays null here and is filled later
 * through the existing admission claim links — a group or B2B booking buys
 * admissions for other people, so the buyer and the eventual holder are
 * never the same column.
 */
final class IssueAdmissionsForRegistration
{
    private const string ADMISSION_GRANT_KIND = 'admission';

    /**
     * @param  list<array{kind: string, role: string, quantity: int, resource_type_id: string|null}>  $grants
     * @return list<Admission>
     */
    public function handle(
        string $registrationId,
        string $eventId,
        string $brandId,
        string $registrantUserId,
        array $grants,
        ?string $amendmentId = null,
    ): array {
        $alreadyIssued = Admission::withoutBrandScope()
            ->where('brand_id', $brandId)
            ->where('registration_id', $registrationId)
            ->where('amendment_id', $amendmentId)
            ->exists();

        if ($alreadyIssued) {
            return [];
        }

        return DB::transaction(function () use ($registrationId, $eventId, $brandId, $registrantUserId, $grants, $amendmentId): array {
            $issued = [];

            foreach ($grants as $grant) {
                if ($grant['kind'] !== self::ADMISSION_GRANT_KIND) {
                    continue;
                }

                $quantity = $grant['quantity'];

                for ($unit = 0; $unit < $quantity; $unit++) {
                    $issued[] = $this->issueOne($brandId, $registrationId, $eventId, $registrantUserId, $grant['role'], $amendmentId);
                }
            }

            return $issued;
        });
    }

    private function issueOne(
        string $brandId,
        string $registrationId,
        string $eventId,
        string $registrantUserId,
        string $role,
        ?string $amendmentId,
    ): Admission {
        // brand_id comes from the event payload rather than the resolved
        // context, so a replay outside any brand context issues into the
        // right brand. It is deliberately not fillable — forceFill states the
        // exception explicitly instead of widening mass assignment.
        $admission = new Admission([
            'registration_id' => $registrationId,
            'amendment_id' => $amendmentId,
            'event_id' => $eventId,
            'holder_user_id' => null,
            'buyer_user_id' => $registrantUserId,
            'role' => $role,
            'valid_from' => null,
            'valid_until' => null,
            'state' => AdmissionState::Issued,
        ]);

        $admission->forceFill(['brand_id' => $brandId])->save();

        event(new AdmissionIssued(
            admissionId: $admission->id,
            registrationId: $admission->registration_id,
            eventId: $admission->event_id,
            brandId: $admission->brand_id,
            role: $admission->role,
            holderUserId: null,
            issuedAt: $admission->created_at->toIso8601String(),
            buyerUserId: $admission->buyer_user_id,
        ));

        return $admission;
    }
}
