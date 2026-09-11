<?php

declare(strict_types=1);

namespace Konventix\Resources\Actions\Concerns;

use Illuminate\Validation\ValidationException;
use Konventix\Resources\Models\ResourceEventWindow;
use Konventix\Resources\Models\ResourceType;

/**
 * What makes a category *the* category of an event: the event must be one
 * this module knows for that brand (its window is projected, ADR-0004 —
 * there is no other way to check an event id without reading Event's
 * tables), and the slug is unique per brand and event
 * (`resource_types` unique index; refused here so the operator sees a
 * field error rather than a 500 from the constraint).
 *
 * Both Actions run on the admin host with no brand in context, so every
 * query escapes the scope explicitly and filters by the brand id it was
 * handed (ADR-0029, .ai/rules/app-modules-src.md).
 */
trait GuardsResourceTypeIdentity
{
    private function guardEvent(string $brandId, string $eventId): void
    {
        if (ResourceEventWindow::forEvent($brandId, $eventId) === null) {
            throw ValidationException::withMessages([
                'eventId' => __('Resources does not know this event for the brand yet.'),
            ]);
        }
    }

    private function guardSlug(string $brandId, string $eventId, string $slug, ?string $exceptId = null): void
    {
        $taken = ResourceType::withoutBrandScope()
            ->where('brand_id', $brandId)
            ->where('event_id', $eventId)
            ->where('slug', $slug)
            ->when($exceptId !== null, fn ($query) => $query->whereKeyNot($exceptId))
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages([
                'slug' => __('Another category of this event already uses this slug.'),
            ]);
        }
    }
}
