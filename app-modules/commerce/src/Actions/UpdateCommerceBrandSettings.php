<?php

declare(strict_types=1);

namespace Konventix\Commerce\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Commerce\Data\CommerceBrandSettingsData;
use Konventix\Commerce\Models\CommerceBrandSettings;
use Konventix\Platform\Models\Brand;

/**
 * Upserts a brand's Commerce settings (ADR-0057 point 4). `brand_id`
 * explicit — the same reasoning `UpdateWaitingRoomSettings` gives: the
 * admin host resolves no brand (ADR-0029), so
 * `CommerceBrandSettings::forBrand()`'s escape hatch is what finds (or
 * seeds) the row this saves onto.
 */
final class UpdateCommerceBrandSettings
{
    public function handle(Brand $brand, CommerceBrandSettingsData $data): CommerceBrandSettings
    {
        return DB::transaction(function () use ($brand, $data): CommerceBrandSettings {
            $settings = CommerceBrandSettings::forBrand($brand->getKey());
            $settings->brand_id = $brand->getKey();

            $settings->fill(['reversal_repayment_days' => $data->reversalRepaymentDays]);
            $settings->save();

            return $settings;
        });
    }
}
