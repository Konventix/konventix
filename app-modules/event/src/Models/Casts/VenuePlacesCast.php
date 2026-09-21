<?php

declare(strict_types=1);

namespace Konventix\Event\Models\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Konventix\Event\Data\VenuePlaceData;

/**
 * Decodes a venue's `parking_spots` / `loading_zones` JSON columns into a
 * typed collection of `VenuePlaceData` — the same shape `OpeningTimesCast`
 * gives `opening_times`. A JSON column is acceptable only behind a typed
 * cast like this one (ADR-0009).
 *
 * @implements CastsAttributes<Collection<int, VenuePlaceData>, iterable<int, VenuePlaceData|array<string, mixed>>>
 */
final class VenuePlacesCast implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     * @return Collection<int, VenuePlaceData>
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): Collection
    {
        if ($value === null) {
            return collect();
        }

        /** @var array<int, array<string, mixed>> $decoded */
        $decoded = json_decode((string) $value, true, 512, JSON_THROW_ON_ERROR);

        return collect($decoded)->map(
            fn (array $place): VenuePlaceData => VenuePlaceData::from($place),
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        /** @var iterable<int, VenuePlaceData|array<string, mixed>> $value */
        $places = collect($value)->map(
            fn (VenuePlaceData|array $place): array => $place instanceof VenuePlaceData
                ? $place->toArray()
                : $place,
        );

        return $places->values()->toJson(JSON_THROW_ON_ERROR);
    }
}
