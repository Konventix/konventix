<?php

declare(strict_types=1);

namespace Konventix\Platform\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Platform\Data\OperatorSettingsData;
use Konventix\Platform\Models\Operator;

/**
 * Saves the operator's own identity, including the legal seller identity every
 * invoice is issued under (ADR-0030).
 *
 * Deliberately boring: no events, because nothing projects from the operator
 * row — Commerce reads it through `OperatorSettings` at the moment it issues a
 * document rather than keeping a copy. An invoice already issued keeps the
 * seller details it was rendered with, since ADR-0031 archives the document
 * itself; correcting a typo here does not and must not rewrite history.
 *
 * Capability `platform.manage-operator` is checked at the controller, matching
 * UpdateBrandTheme's precedent until the ADR-0015 dispatcher exists.
 */
final class UpdateOperatorSettings
{
    public function handle(Operator $operator, OperatorSettingsData $data): Operator
    {
        return DB::transaction(function () use ($operator, $data): Operator {
            $operator->update([
                'name' => $data->name,
                'legal_name' => $data->legalName,
                'contact_email' => $data->contactEmail,
                'address_line' => $data->addressLine,
                'postal_code' => $data->postalCode,
                'city' => $data->city,
                'country_code' => strtoupper($data->countryCode),
                'vat_id' => $data->vatId,
                'tax_number' => $data->taxNumber,
                'small_business_scheme' => $data->smallBusinessScheme,
            ]);

            return $operator->refresh();
        });
    }
}
