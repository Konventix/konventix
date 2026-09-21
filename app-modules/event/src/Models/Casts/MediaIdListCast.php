<?php

declare(strict_types=1);

namespace Konventix\Event\Models\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * An ordered list of media-library ULIDs (ADR-0035) in a JSON column —
 * a venue's `image_media_ids`. Order is the operator's: the first entry is
 * shown first unless a primary is named. Duplicates and blanks are dropped
 * on write, so a picture appears at most once and the primary-membership
 * rule on `Venue` has one list to check against.
 *
 * @implements CastsAttributes<list<string>, iterable<int, string|null>|null>
 */
final class MediaIdListCast implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     * @return list<string>
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null) {
            return [];
        }

        /** @var array<int, mixed> $decoded */
        $decoded = json_decode((string) $value, true, 512, JSON_THROW_ON_ERROR);

        return self::normalise($decoded);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        /** @var iterable<int, mixed> $value */
        return json_encode(self::normalise(collect($value)->all()), JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<int, mixed>  $ids
     * @return list<string>
     */
    public static function normalise(array $ids): array
    {
        $clean = [];

        foreach ($ids as $id) {
            if (! is_string($id) || $id === '' || in_array($id, $clean, true)) {
                continue;
            }

            $clean[] = $id;
        }

        return $clean;
    }
}
