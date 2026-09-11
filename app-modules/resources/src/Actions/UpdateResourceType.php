<?php

declare(strict_types=1);

namespace Konventix\Resources\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Konventix\Resources\Actions\Concerns\GuardsResourceTypeIdentity;
use Konventix\Resources\Data\ResourceTypeData;
use Konventix\Resources\Models\Resource;
use Konventix\Resources\Models\ResourceType;

/**
 * Edits a category. The event is frozen once the category has places: a
 * place belongs to a plan of one event, and moving its category to another
 * event would leave the plan's projection pointing across events. The
 * capacity mode is frozen for the same reason — the exclusion constraint's
 * `is_exclusive` is denormalised from it onto every booking (ADR-0034).
 *
 * The freeze checks whether the category has *any* place row, published or
 * not — a place under construction is still anchored to this event and
 * mode, so it freezes the axes the same as a published one would
 * (`ResourceTypeAdminController` shows the operator a different,
 * *bookable*-only number for sizing a quota; the two counts answer
 * different questions on purpose).
 */
final class UpdateResourceType
{
    use GuardsResourceTypeIdentity;

    public function handle(ResourceType $type, ResourceTypeData $data): ResourceType
    {
        return DB::transaction(function () use ($type, $data): ResourceType {
            $this->guardEvent($type->brand_id, $data->eventId);
            $this->guardSlug($type->brand_id, $data->eventId, $data->slug, exceptId: (string) $type->getKey());
            $this->guardFrozenAxes($type, $data);

            $type->forceFill([
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

    private function guardFrozenAxes(ResourceType $type, ResourceTypeData $data): void
    {
        $hasPlaces = Resource::withoutBrandScope()
            ->where('resource_type_id', $type->getKey())
            ->exists();

        if (! $hasPlaces) {
            return;
        }

        $errors = [];

        if ($type->event_id !== $data->eventId) {
            $errors['eventId'] = __('This category already has places; it stays with its event.');
        }

        if ($type->mode !== $data->mode) {
            $errors['mode'] = __('This category already has places; its capacity mode is frozen.');
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
