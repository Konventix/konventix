<?php

declare(strict_types=1);

namespace Konventix\Resources\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Resources\Actions\Concerns\GuardsResourceTypeIdentity;
use Konventix\Resources\Data\ResourceTypeData;
use Konventix\Resources\Models\ResourceType;

/**
 * Creates a category (design §4.1) from the console. The brand comes from
 * the request, not from context: the admin host resolves none (ADR-0029),
 * so `brand_id` is force-filled before the save and `BelongsToBrand`'s
 * creating hook finds it already set.
 */
final class CreateResourceType
{
    use GuardsResourceTypeIdentity;

    public function handle(string $brandId, ResourceTypeData $data): ResourceType
    {
        return DB::transaction(function () use ($brandId, $data): ResourceType {
            $this->guardEvent($brandId, $data->eventId);
            $this->guardSlug($brandId, $data->eventId, $data->slug);

            $type = new ResourceType;
            $type->forceFill([
                'brand_id' => $brandId,
                'event_id' => $data->eventId,
                'name' => $data->name,
                'slug' => $data->slug,
                'mode' => $data->mode,
                'hold_minutes' => $data->holdMinutes,
                'description' => $data->description,
                'token' => $data->token,
                'symbol' => $data->symbol,
                'sort_order' => $data->sortOrder,
                'default_shape' => $data->defaultShape,
            ])->save();

            return $type;
        });
    }
}
