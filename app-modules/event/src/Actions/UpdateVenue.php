<?php

declare(strict_types=1);

namespace Konventix\Event\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Event\Actions\Concerns\SavesVenueDetails;
use Konventix\Event\Data\VenueData;
use Konventix\Event\Events\VenueUpdated;
use Konventix\Event\Models\Venue;
use Konventix\Platform\Media\MediaLibrary;

/**
 * Updates a venue's address, arrival details and pictures (SPEC.md "Public
 * contract" — `VenueUpdated`). The slug, the pictures' brand ownership and
 * the media library's usage rows are settled in
 * `Concerns\SavesVenueDetails`, shared with `CreateVenue`.
 */
final class UpdateVenue
{
    use SavesVenueDetails;

    public function __construct(private readonly MediaLibrary $media) {}

    public function handle(Venue $venue, VenueData $data): Venue
    {
        return DB::transaction(function () use ($venue, $data): Venue {
            $this->guardOwnedImages($this->media, $venue->brand_id, $data);

            $before = $venue->image_media_ids;

            $venue->forceFill([
                ...$data->toModelAttributes(),
                'slug' => $this->resolveSlug($venue->brand_id, $data, $venue),
            ])->save();

            $this->syncImageUsage($this->media, $venue, $before, $venue->image_media_ids);

            event(new VenueUpdated(
                venueId: $venue->id,
                brandId: $venue->brand_id,
                name: $venue->name,
                city: $venue->city,
                countryCode: $venue->country_code,
            ));

            return $venue->refresh();
        });
    }
}
