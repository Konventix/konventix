<?php

declare(strict_types=1);

namespace Konventix\Registration\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Registration\Actions\Concerns\GuardsTypeConfiguration;
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
            ]);

            $type->forceFill(['brand_id' => $brandId])->save();

            $this->syncQuotas($type, $data->quotaIds);

            return $type->refresh();
        });
    }
}
