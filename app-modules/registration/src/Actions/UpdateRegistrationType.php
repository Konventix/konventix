<?php

declare(strict_types=1);

namespace Konventix\Registration\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Registration\Actions\Concerns\GuardsTypeConfiguration;
use Konventix\Registration\Actions\Concerns\SyncsTypeQuotas;
use Konventix\Registration\Data\RegistrationTypeData;
use Konventix\Registration\Models\RegistrationType;

/**
 * Edits an offer.
 *
 * Once registrations exist against the type, its terms freeze: event,
 * approval, settlement, gate, payment terms, grants and price may no longer
 * change, and only the name, the visibility and the quota links stay
 * editable. That is not caution — the Actions read the type's *current* axes
 * when a payment lands or an approval is given, so editing them under live
 * registrations reinterprets those registrations rather than migrating them.
 * A different offer is a new type; the SPEC says the same about amendments
 * ("a type change re-opens settlement, approval and gate at once").
 */
final class UpdateRegistrationType
{
    use GuardsTypeConfiguration;
    use SyncsTypeQuotas;

    public function handle(RegistrationType $type, RegistrationTypeData $data): RegistrationType
    {
        $this->guardConfiguration($data);
        $this->guardFrozenAxes($type, $data);

        return DB::transaction(function () use ($type, $data): RegistrationType {
            $type->forceFill([
                'event_id' => $data->eventId,
                'name' => $data->name,
                'visibility' => $data->visibility,
                'approval' => $data->approval,
                'settlement' => $data->settlement,
                'confirmation_gate' => $data->confirmationGate,
                'grants' => $data->grants,
                'price_declaration' => $data->priceDeclaration,
                'terms_days' => $data->termsDays,
                // Not a frozen axis (#218): closing a sale early or extending
                // it by a day is an operational decision — it reinterprets
                // nobody's existing registration the way the terms would.
                'sales_start' => $data->salesStart,
                'sales_end' => $data->salesEnd,
            ])->save();

            $this->syncQuotas($type, $data->quotaIds);

            return $type->refresh();
        });
    }
}
