<?php

declare(strict_types=1);

namespace Konventix\Content\Actions\Concerns;

use Illuminate\Validation\ValidationException;
use Konventix\Content\Models\ContentEntry;

/**
 * `content_entries` is unique on (brand_id, slug), and a slug collision is an
 * editor mistake rather than a system fault — so it surfaces as a field error
 * on the form, not a 500 from the database driver.
 *
 * The check runs `withoutBrandScope()` against an explicit brand id on
 * purpose: the operator console hardens `BrandScope` to zero rows
 * (ADR-0029), where a scoped uniqueness query would happily report every
 * slug as free.
 */
trait GuardsUniqueSlug
{
    private function guardUniqueSlug(string $brandId, string $slug, ?string $ignoreEntryId = null): void
    {
        $taken = ContentEntry::withoutBrandScope()
            ->where('brand_id', $brandId)
            ->where('slug', $slug)
            ->when($ignoreEntryId !== null, fn ($query) => $query->whereKeyNot($ignoreEntryId))
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages([
                'slug' => __('That slug is already used by another entry in this brand.'),
            ]);
        }
    }
}
