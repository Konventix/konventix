<?php

declare(strict_types=1);

namespace Konventix\Event\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Event\Data\VenueData;
use Konventix\Event\Events\VenueUpdated;
use Konventix\Event\Models\Venue;

/**
 * Updates a venue's address/access details (SPEC.md "Public contract" —
 * `VenueUpdated`).
 */
final class UpdateVenue
{
    public function handle(Venue $venue, VenueData $data): Venue
    {
        return DB::transaction(function () use ($venue, $data): Venue {
            $venue->forceFill($data->toModelAttributes())->save();

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
