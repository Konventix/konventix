<?php

declare(strict_types=1);

namespace Konventix\Event\Actions\Concerns;

use Illuminate\Validation\ValidationException;
use Konventix\Event\Models\Event;

/**
 * The slug rules `CreateEvent` and `UpdateEventDetails` share. Within one
 * brand, `slug` and `legacy_slug` (#309) form a single namespace under
 * `/events/…`: a slug is unique among slugs and never another event's legacy
 * slug, and a legacy slug is never any event's slug (its own included) and
 * never another event's legacy slug. Otherwise one URL would mean two events,
 * or the redirect would point at a page that already answers itself.
 *
 * The database holds only the two per-column unique indexes; the cross-column
 * half lives here, as form errors on the field that collides.
 */
trait GuardsEventSlugs
{
    private function guardSlugs(string $brandId, string $slug, ?string $legacySlug, ?string $eventId = null): void
    {
        $others = fn () => Event::withoutBrandScope()
            ->where('brand_id', $brandId)
            ->when($eventId !== null, fn ($query) => $query->whereKeyNot($eventId));

        if ($others()->where('slug', $slug)->exists()) {
            throw ValidationException::withMessages([
                'slug' => __('This slug is already used by another event of this brand.'),
            ]);
        }

        if ($others()->where('legacy_slug', $slug)->exists()) {
            throw ValidationException::withMessages([
                'slug' => __('This slug is already the legacy slug of another event of this brand.'),
            ]);
        }

        if ($legacySlug === null) {
            return;
        }

        if ($legacySlug === $slug) {
            throw ValidationException::withMessages([
                'legacySlug' => __('The legacy slug must differ from the slug.'),
            ]);
        }

        if ($others()->where('slug', $legacySlug)->exists()) {
            throw ValidationException::withMessages([
                'legacySlug' => __('This legacy slug is already the slug of another event of this brand.'),
            ]);
        }

        if ($others()->where('legacy_slug', $legacySlug)->exists()) {
            throw ValidationException::withMessages([
                'legacySlug' => __('This legacy slug is already used by another event of this brand.'),
            ]);
        }
    }
}
