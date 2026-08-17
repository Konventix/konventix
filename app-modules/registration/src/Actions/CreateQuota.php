<?php

declare(strict_types=1);

namespace Konventix\Registration\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Registration\Data\QuotaData;
use Konventix\Registration\Models\Quota;

/**
 * Creates a quota. `consumed` starts at zero and is only ever moved by the
 * registration Actions under a row lock (SPEC.md "Owns" — `Quota`).
 *
 * `brand_id` is force-filled from the caller: the operator console creates
 * quotas for a brand it is not resolved into (ADR-0029).
 */
final class CreateQuota
{
    public function handle(string $brandId, QuotaData $data): Quota
    {
        return DB::transaction(function () use ($brandId, $data): Quota {
            $quota = new Quota([
                'name' => $data->name,
                'capacity' => $data->capacity,
                'consumed' => 0,
            ]);

            $quota->forceFill(['brand_id' => $brandId])->save();

            return $quota;
        });
    }
}
