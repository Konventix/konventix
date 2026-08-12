<?php

declare(strict_types=1);

namespace Konventix\Event\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Event\Data\VenueData;
use Konventix\Event\Events\VenueCreated;
use Konventix\Event\Models\Venue;

/**
 * Creates a brand-owned, reusable venue (SPEC.md "Owns" — Venue). Brand is
 * explicit for the same ADR-0029 reason as `CreateEvent`.
 */
final class CreateVenue
{
    public function handle(string $brandId, VenueData $data): Venue
    {
        return DB::transaction(function () use ($brandId, $data): Venue {
            $venue = new Venue($data->toModelAttributes());
            $venue->brand_id = $brandId;
            $venue->save();

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
