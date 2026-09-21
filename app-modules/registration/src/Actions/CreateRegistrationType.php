<?php

declare(strict_types=1);

namespace Konventix\Registration\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Registration\Actions\Concerns\GuardsTypeConfiguration;
use Konventix\Registration\Actions\Concerns\SyncsTypeEntryConditions;
use Konventix\Registration\Actions\Concerns\SyncsTypeQuotas;
use Konventix\Registration\Data\RegistrationTypeData;
use Konventix\Registration\Models\RegistrationType;

/**
 * Creates the offer (ADR-0005). The axis combination is guarded here rather
 * than in the form, so a type created from tinker or a seeder is refused on
 * exactly the same grounds as one saved from the console.
 *
 * `brand_id` is passed in and force-filled: the operator console creates
 * types for a brand it is not resolved into (ADR-0029).
 */
final class CreateRegistrationType
{
    use GuardsTypeConfiguration;
    use SyncsTypeEntryConditions;
    use SyncsTypeQuotas;

    public function handle(string $brandId, RegistrationTypeData $data): RegistrationType
    {
        $this->guardConfiguration($data);

        return DB::transaction(function () use ($brandId, $data): RegistrationType {
            $type = new RegistrationType([
                'event_id' => $data->eventId,
                'name' => $data->name,
                'visibility' => $data->visibility,
                'approval' => $data->approval,
                'settlement' => $data->settlement,
                'confirmation_gate' => $data->confirmationGate,
                'grants' => $data->grants,
                'price_declaration' => $data->priceDeclaration,
                'terms_days' => $data->termsDays,
                // Not a frozen axis either (Task 19): the ceiling applies to
                // the next submission — the quantity an existing
                // registration bought sits on its own row and is not
                // reinterpreted by lowering it.
                'max_quantity_per_registration' => $data->maxQuantityPerRegistration,
                // Not a frozen axis (#218): closing a sale early or extending
                // it by a day is an operational decision — it reinterprets
                // nobody's existing registration the way the terms would.
                // Normalised to UTC (#237): the payload carries the
                // browser's own offset, and a zone-less column would
                // otherwise store the operator's wall-clock digits.
                'sales_start' => $data->salesStartInstant(),
                'sales_end' => $data->salesEndInstant(),
                // D-REG-21: the "buy the regular ticket instead" pointer.
                // Not an axis — it grants nothing and may be set or cleared
                // at any time.
                'alternative_registration_type_id' => $data->alternativeRegistrationTypeId,
            ]);

            $type->forceFill(['brand_id' => $brandId])->save();

            $this->guardAlternativeType($type, $data->alternativeRegistrationTypeId, $data->eventId);
            $this->syncQuotas($type, $data->quotaIds);
            // Not a frozen axis either (D-REG-19): which declarations a type
            // asks for stays editable after it has sold, because the
            // evidence is each assertion's own snapshot.
            $this->syncEntryConditions($type, $data->entryConditionIds);

            return $type->refresh();
        });
    }
}
