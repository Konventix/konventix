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
                'contact_email' => $this->orNull($data->contactEmail),
                'address_line' => $this->orNull($data->addressLine),
                'postal_code' => $this->orNull($data->postalCode),
                'city' => $this->orNull($data->city),
                'country_code' => strtoupper($data->countryCode),
                'vat_id' => $this->orNull($data->vatId),
                'tax_number' => $this->orNull($data->taxNumber),
                'small_business_scheme' => $data->smallBusinessScheme,
                'signups_open' => $data->signupsOpen,
                'date_format' => $data->dateFormat->value,
                'time_format' => $data->timeFormat->value,
                'locale' => $data->locale,
            ]);

            return $operator->refresh();
        });
    }

    /**
     * A text input cannot submit null, so a field the operator cleared arrives
     * as an empty string. Stored as-is it would read as "deliberately set to
     * nothing", and `contactEmailForBrand()` would hand Compliance an empty
     * address to escalate a statutory deadline to — which fails silently
     * rather than loudly. Empty means absent.
     */
    private function orNull(?string $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
