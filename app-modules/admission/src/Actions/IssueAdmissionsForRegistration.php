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
 * Idempotent and replayable (TODO.md): guarded by "does this registration
 * already have any admissions at all?" rather than a per-grant key, which is
 * sufficient because a registration is confirmed — and so this Action
 * called — exactly once per confirmation. `withoutBrandScope()` is the named
 * escape hatch (ADR-0010): the check must hold regardless of which brand is
 * ambient when a replay happens, so it is scoped explicitly from the
 * payload's own `brandId` instead.
 *
 * `'admission'` is written as a literal rather than importing
 * `Konventix\Registration\Enums\GrantKind`: this module does not import
 * Registration at all (see
 * `tests/RegistrationCommerceImportBoundaryTest.php`), so the string is the
 * contract, not the enum.
 */
final class IssueAdmissionsForRegistration
{
    private const string ADMISSION_GRANT_KIND = 'admission';

    /**
     * @param  list<array{kind: string, role: string, quantity: int, resource_type_id: string|null}>  $grants
     * @return list<Admission>
     */
    public function handle(string $registrationId, string $eventId, string $brandId, array $grants): array
    {
        $alreadyIssued = Admission::withoutBrandScope()
            ->where('brand_id', $brandId)
            ->where('registration_id', $registrationId)
            ->exists();

        if ($alreadyIssued) {
            return [];
        }

        return DB::transaction(function () use ($registrationId, $eventId, $brandId, $grants): array {
            $issued = [];

            foreach ($grants as $grant) {
                if ($grant['kind'] !== self::ADMISSION_GRANT_KIND) {
                    continue;
                }

                $quantity = $grant['quantity'];

                for ($unit = 0; $unit < $quantity; $unit++) {
                    $issued[] = $this->issueOne($brandId, $registrationId, $eventId, $grant['role']);
                }
            }

            return $issued;
        });
    }

    private function issueOne(string $brandId, string $registrationId, string $eventId, string $role): Admission
    {
        // brand_id comes from the event payload rather than the resolved
        // context, so a replay outside any brand context issues into the
        // right brand. It is deliberately not fillable — forceFill states the
        // exception explicitly instead of widening mass assignment.
        $admission = new Admission([
            'registration_id' => $registrationId,
            'event_id' => $eventId,
            'holder_user_id' => null,
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
        ));

        return $admission;
    }
}
