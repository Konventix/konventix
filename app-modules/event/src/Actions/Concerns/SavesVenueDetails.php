<?php

declare(strict_types=1);

namespace Konventix\Event\Actions\Concerns;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Konventix\Event\Data\VenueData;
use Konventix\Event\Models\Venue;
use Konventix\Platform\Media\MediaLibrary;

/**
 * What `CreateVenue` and `UpdateVenue` do identically: settle the slug,
 * refuse pictures from another brand's library, and keep the library's
 * usage bookkeeping in step (ADR-0035) — the same three moves
 * `UpdateEventDetails` makes for an event's slug and banner, for a venue's
 * slug and its ordered set of pictures.
 */
trait SavesVenueDetails
{
    /**
     * The slug to store. A slug the operator typed is taken literally and a
     * collision is a form error on `slug`, the way `UpdateEventDetails`
     * answers one. A blank one is derived from the name and then made free
     * by counting up — the console's create dialog has no slug field, so
     * "Main Hall" twice must produce `main-hall` and `main-hall-2` rather
     * than an error about a field the operator cannot see. Deterministic
     * either way, and the same rule the migration's backfill follows.
     */
    private function resolveSlug(string $brandId, VenueData $data, ?Venue $venue = null): string
    {
        $submitted = $data->slug === null ? '' : trim($data->slug);

        if ($submitted !== '') {
            if ($this->slugTaken($brandId, $submitted, $venue)) {
                throw ValidationException::withMessages([
                    'slug' => __('This slug is already used by another venue of this brand.'),
                ]);
            }

            return $submitted;
        }

        $base = Str::slug($data->name);
        $base = $base === '' ? 'venue' : $base;
        $slug = $base;
        $suffix = 2;

        while ($this->slugTaken($brandId, $slug, $venue)) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }

    private function slugTaken(string $brandId, string $slug, ?Venue $venue): bool
    {
        return Venue::withoutBrandScope()
            ->where('brand_id', $brandId)
            ->where('slug', $slug)
            ->when($venue !== null, fn ($query) => $query->whereKeyNot($venue->getKey()))
            ->exists();
    }

    /**
     * A ULID from another brand's library would resolve and render — the
     * library ignores brand scope on read — and vanish the day that brand
     * is deleted (`UpdateEventDetails::guardOwnedBanner()` says the same for
     * the event banner). The primary is checked with the rest: it is a
     * member of the list by `VenueData`'s own rule, but a payload that
     * skipped the form would not know that.
     */
    private function guardOwnedImages(MediaLibrary $media, string $brandId, VenueData $data): void
    {
        $ids = $data->imageMediaIds;

        if ($data->primaryMediaId !== null && ! in_array($data->primaryMediaId, $ids, true)) {
            $ids[] = $data->primaryMediaId;
        }

        foreach ($ids as $mediaId) {
            if (! $media->belongsToBrand($mediaId, $brandId)) {
                throw ValidationException::withMessages([
                    'imageMediaIds' => __('That file belongs to a different brand.'),
                ]);
            }
        }
    }

    /**
     * The library tracks who wears a picture so it can refuse to delete one
     * still in use (ADR-0035) — `UpdateEventDetails::syncBannerUsage()` for
     * a single banner, this for an ordered set. Recorded under this
     * module's own name, one usage row per picture.
     *
     * @param  list<string>  $before
     * @param  list<string>  $after
     */
    private function syncImageUsage(MediaLibrary $media, Venue $venue, array $before, array $after): void
    {
        foreach (array_diff($before, $after) as $released) {
            $media->releaseUsage($released, 'event', 'venue', $venue->getKey());
        }

        foreach (array_diff($after, $before) as $recorded) {
            $media->recordUsage($recorded, 'event', 'venue', $venue->getKey());
        }
    }
}
