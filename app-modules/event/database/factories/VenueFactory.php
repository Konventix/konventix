<?php

declare(strict_types=1);

namespace Konventix\Event\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
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
        return [
            'name' => fake()->company().' Hall',
            'address_line1' => fake()->streetAddress(),
            'address_line2' => null,
            'postal_code' => fake()->postcode(),
            'city' => fake()->city(),
            'country_code' => fake()->countryCode(),
            'latitude' => fake()->latitude(),
            'longitude' => fake()->longitude(),
            'access_notes' => null,
            'opening_times' => [],
        ];
    }
}
