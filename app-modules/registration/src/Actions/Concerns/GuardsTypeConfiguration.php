<?php

declare(strict_types=1);

namespace Konventix\Registration\Actions\Concerns;

use Illuminate\Validation\ValidationException;
use Konventix\Registration\Data\GrantData;
use Konventix\Registration\Data\RegistrationTypeData;
use Konventix\Registration\Enums\ConfirmationGate;
use Konventix\Registration\Enums\RegistrationSettlement;
use Konventix\Registration\Models\Registration;
use Konventix\Registration\Models\RegistrationType;

/**
 * The combinations of axes a `RegistrationType` may hold (ADR-0005,
 * ADR-0017). Two of them are configuration mistakes that only surface much
 * later, at the worst moment, so they are refused at the source:
 *
 * - A priced settlement with no price declaration. `RequestSettlement` throws
 *   on it — at submission, in front of a registrant, for a type an organizer
 *   saved weeks earlier.
 * - `confirmation_gate: on_payment` on a settlement that never requests
 *   payment. Nothing would ever confirm such a registration: it would sit in
 *   `submitted` forever with no way for anyone to notice why.
 *
 * The third guard is about time rather than logic. Once registrations exist
 * against a type, its terms are what people agreed to; the axes freeze and
 * only the label, its visibility and its quota links stay editable. Changing
 * a settlement or a gate under live registrations does not migrate them — it
 * silently reinterprets them, because the Actions read the type's *current*
 * values when a payment lands or an approval is given.
 *
 * The fourth guard, `GuardsSeatingGrants`, covers the seating grant's own
 * cross-field rules (seating design §7.1).
 */
trait GuardsTypeConfiguration
{
    use GuardsSeatingGrants;

    private function guardConfiguration(RegistrationTypeData $data): void
    {
        $priced = $data->settlement->requestsSettlement();

        if ($priced && $data->priceDeclaration === null) {
            throw ValidationException::withMessages([
                'priceDeclaration' => __('A :settlement type needs a price declaration.', [
                    'settlement' => $data->settlement->value,
                ]),
            ]);
        }

        if ($data->settlement === RegistrationSettlement::Free && $data->priceDeclaration !== null) {
            throw ValidationException::withMessages([
                'priceDeclaration' => __('A free type cannot carry a price.'),
            ]);
        }

        if ($data->confirmationGate === ConfirmationGate::OnPayment && ! $priced) {
            throw ValidationException::withMessages([
                'confirmationGate' => __('Confirming on payment needs a settlement that asks for payment.'),
            ]);
        }

        $this->guardSeatingGrants($data->grants);
    }

    /**
     * @return list<string> the axes that may no longer change
     */
    private function frozenAxes(RegistrationType $type, RegistrationTypeData $data): array
    {
        $hasRegistrations = Registration::withoutBrandScope()
            ->where('registration_type_id', $type->getKey())
            ->exists();

        if (! $hasRegistrations) {
            return [];
        }

        $changed = [];

        $comparisons = [
            'eventId' => [$type->event_id, $data->eventId],
            'approval' => [$type->approval, $data->approval],
            'settlement' => [$type->settlement, $data->settlement],
            'confirmationGate' => [$type->confirmation_gate, $data->confirmationGate],
            'termsDays' => [$type->terms_days, $data->termsDays],
            'grants' => [
                json_encode($type->grants->toArray()),
                json_encode(array_map(fn (GrantData $grant): array => $grant->toArray(), $data->grants)),
            ],
            'priceDeclaration' => [
                $type->price_declaration?->toJson(),
                $data->priceDeclaration?->toJson(),
            ],
        ];

        foreach ($comparisons as $field => [$current, $next]) {
            if ($current !== $next) {
                $changed[] = $field;
            }
        }

        return $changed;
    }

    private function guardFrozenAxes(RegistrationType $type, RegistrationTypeData $data): void
    {
        $changed = $this->frozenAxes($type, $data);

        if ($changed === []) {
            return;
        }

        throw ValidationException::withMessages(array_fill_keys(
            $changed,
            __('This type already has registrations; its terms are frozen. Create a new type instead.'),
        ));
    }
}
