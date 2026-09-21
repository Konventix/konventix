<?php

declare(strict_types=1);

namespace Konventix\Event\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;
use Konventix\Event\Data\OpeningPeriodData;
use Konventix\Event\Data\VenuePlaceData;
use Konventix\Event\Models\Venue;
use Konventix\Platform\Media\MediaLibrary;

/**
 * The public venue page (SPEC.md "Owns" — Venue): pictures, description,
 * how to get there, where to park, where to unload, and the opening times
 * of the site, on the brand's own domain.
 *
 * A venue has no visibility of its own. It is public exactly while one of
 * the events assigned to it is (`Venue::isPubliclyReachable()`), so a venue
 * an operator keeps for next year stays a console record and this route
 * 404s — the same answer `EventLandingController::show()` gives a draft
 * event.
 *
 * Every section is optional. An attribute nobody filled in is absent from
 * the props rather than empty, and the page renders no heading for it.
 */
final class VenueLandingController
{
    public function show(Venue $venue, MediaLibrary $media): Response
    {
        abort_unless($venue->isPubliclyReachable(), 404);

        return Inertia::render('event/Venue', [
            'venue' => [
                'name' => $venue->name,
                'description' => $venue->description,
                'addressLine1' => $venue->address_line1,
                'addressLine2' => $venue->address_line2,
                'postalCode' => $venue->postal_code,
                'city' => $venue->city,
                'countryCode' => $venue->country_code,
                'accessNotes' => $venue->access_notes,
                'publicTransport' => $venue->public_transport,
                'seatPlanReference' => $venue->seat_plan_reference,
                // "Open in maps" is a plain OpenStreetMap link, no embed and
                // no map dependency. The entrance is what a visitor wants to
                // be sent to; the building's own coordinates stand in when
                // nobody has pinned the door yet.
                'mapsUrl' => $this->mapsUrl(
                    $venue->entry_latitude ?? $venue->latitude,
                    $venue->entry_longitude ?? $venue->longitude,
                ),
            ],
            'images' => $this->images($venue, $media),
            'openingTimes' => $venue->opening_times
                ->map(fn (OpeningPeriodData $period): array => [
                    'dayOfWeek' => $period->dayOfWeek,
                    'opensAt' => $period->opensAt,
                    'closesAt' => $period->closesAt,
                ])->all(),
            'parkingSpots' => $this->places($venue->parking_spots->all()),
            'loadingZones' => $this->places($venue->loading_zones->all()),
        ]);
    }

    /**
     * The pictures in the order the page shows them — the primary first —
     * resolved through the library, which answers null for one that has
     * since been deleted (ADR-0035). A stale reference is skipped, so the
     * carousel never holds a broken image.
     *
     * @return list<array{url: string, alt: string|null}>
     */
    private function images(Venue $venue, MediaLibrary $media): array
    {
        $images = [];

        foreach ($venue->orderedImageMediaIds() as $mediaId) {
            $reference = $media->referenceFor($mediaId);

            if ($reference === null) {
                continue;
            }

            $images[] = ['url' => $reference['url'], 'alt' => $reference['altText']];
        }

        return $images;
    }

    /**
     * @param  array<int, VenuePlaceData>  $places
     * @return list<array{label: string, address: string|null, mapsUrl: string|null}>
     */
    private function places(array $places): array
    {
        return array_values(array_map(fn (VenuePlaceData $place): array => [
            'label' => $place->label,
            'address' => $place->address,
            'mapsUrl' => $this->mapsUrl(
                $place->latitude === null ? null : (string) $place->latitude,
                $place->longitude === null ? null : (string) $place->longitude,
            ),
        ], $places));
    }

    /**
     * OpenStreetMap's own marker URL. A pair is all or nothing: half a
     * coordinate points at the Greenwich meridian or the equator, which is
     * worse than no link.
     */
    private function mapsUrl(?string $latitude, ?string $longitude): ?string
    {
        if ($latitude === null || $longitude === null) {
            return null;
        }

        return sprintf(
            'https://www.openstreetmap.org/?mlat=%s&mlon=%s#map=18/%s/%s',
            $latitude,
            $longitude,
            $latitude,
            $longitude,
        );
    }
}
