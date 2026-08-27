<?php

declare(strict_types=1);

namespace Konventix\Commerce\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Commerce\Data\WaitingRoomSettingsData;
use Konventix\Commerce\Models\WaitingRoomSettings;
use Konventix\Platform\Models\Brand;

/**
 * Upserts a brand's waiting-room settings (ADR-0047 point 5). `brand_id`
 * explicit — same reasoning as `SaveProduct`: the admin host resolves no
 * brand (ADR-0029), so `WaitingRoomSettings::forBrand()`'s escape hatch is
 * what finds (or seeds) the row this saves onto.
 */
final class UpdateWaitingRoomSettings
{
    public function handle(Brand $brand, WaitingRoomSettingsData $data): WaitingRoomSettings
    {
        return DB::transaction(function () use ($brand, $data): WaitingRoomSettings {
            $settings = WaitingRoomSettings::forBrand($brand->getKey());
            $settings->brand_id = $brand->getKey();

            $settings->fill([
                'admit_per_interval' => $data->admitPerInterval,
                'admission_ttl_minutes' => $data->admissionTtlMinutes,
            ]);
            $settings->save();

            return $settings;
        });
    }
}
