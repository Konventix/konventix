<?php

declare(strict_types=1);

namespace Konventix\Event\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Konventix\Event\Models\Venue;

/**
 * @extends Factory<Venue>
 */
class VenueFactory extends Factory
{
    /** @var class-string<Venue> */
    protected $model = Venue::class;

    /**
     * Note there is no `brand_id` here on purpose: BelongsToBrand stamps it
     * from the resolved brand context, and a test that forgets to establish
     * one should fail loudly rather than silently inventing a brand.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->company().' Hall';

        return [
            'name' => $name,
            // Unique per brand in the database; unique per *run* here, since
            // a factory that reuses a name would otherwise collide on the
            // second row of the same brand.
            'slug' => Str::slug($name).'-'.Str::lower((string) Str::ulid()),
            'address_line1' => fake()->streetAddress(),
            'address_line2' => null,
            'postal_code' => fake()->postcode(),
            'city' => fake()->city(),
            'country_code' => fake()->countryCode(),
            'latitude' => fake()->latitude(),
            'longitude' => fake()->longitude(),
            'access_notes' => null,
            'description' => null,
            'entry_latitude' => null,
            'entry_longitude' => null,
            'seat_plan_reference' => null,
            'public_transport' => null,
            'parking_spots' => [],
            'loading_zones' => [],
            'image_media_ids' => [],
            'primary_media_id' => null,
            'opening_times' => [],
        ];
    }
}
