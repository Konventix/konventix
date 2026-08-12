<?php

declare(strict_types=1);

namespace Konventix\Event\Models\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Konventix\Event\Data\OpeningPeriodData;

/**
 * Decodes a venue's `opening_times` JSON column into a typed collection of
 * `OpeningPeriodData` (SPEC.md — Venue's "opening times of the site"). A JSON
 * column is acceptable only behind a typed cast like this one (ADR-0009).
 *
 * @implements CastsAttributes<Collection<int, OpeningPeriodData>, iterable<int, OpeningPeriodData|array<string, mixed>>>
 */
final class OpeningTimesCast implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     * @return Collection<int, OpeningPeriodData>
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): Collection
    {
        if ($value === null) {
            return collect();
        }

        /** @var array<int, array<string, mixed>> $decoded */
        $decoded = json_decode((string) $value, true, 512, JSON_THROW_ON_ERROR);

        return collect($decoded)->map(
            fn (array $period): OpeningPeriodData => OpeningPeriodData::from($period),
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

        /** @var iterable<int, OpeningPeriodData|array<string, mixed>> $value */
        $periods = collect($value)->map(
            fn (OpeningPeriodData|array $period): array => $period instanceof OpeningPeriodData
                ? $period->toArray()
                : $period,
        );

        return $periods->toJson(JSON_THROW_ON_ERROR);
    }
}
