<?php

declare(strict_types=1);

namespace Konventix\Event\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Event\Actions\Concerns\SavesVenueDetails;
use Konventix\Event\Data\VenueData;
use Konventix\Event\Events\VenueCreated;
use Konventix\Event\Models\Venue;
use Konventix\Platform\Media\MediaLibrary;

/**
 * Creates a brand-owned, reusable venue (SPEC.md "Owns" — Venue). Brand is
 * explicit for the same ADR-0029 reason as `CreateEvent`.
 *
 * The slug the public page is reached by, the ownership of every picture
 * and the library's usage bookkeeping are settled here rather than in the
 * controller — see `Concerns\SavesVenueDetails`.
 */
final class CreateVenue
{
    use SavesVenueDetails;

    public function __construct(private readonly MediaLibrary $media) {}

    public function handle(string $brandId, VenueData $data): Venue
    {
        return DB::transaction(function () use ($brandId, $data): Venue {
            $this->guardOwnedImages($this->media, $brandId, $data);

            $venue = new Venue($data->toModelAttributes());
            $venue->brand_id = $brandId;
            $venue->slug = $this->resolveSlug($brandId, $data);
            $venue->save();

            $this->syncImageUsage($this->media, $venue, [], $venue->image_media_ids);

            event(new VenueCreated(
                venueId: $venue->id,
                brandId: $venue->brand_id,
                name: $venue->name,
                city: $venue->city,
                countryCode: $venue->country_code,
            ));

            return $venue;
        });
    }
}
