<?php

declare(strict_types=1);

namespace Konventix\Agenda\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Konventix\Agenda\Models\Location;

/**
 * @extends Factory<Location>
 */
class LocationFactory extends Factory
{
    /** @var class-string<Location> */
    protected $model = Location::class;

    /**
     * No `brand_id` here on purpose: BelongsToBrand stamps it from the
     * resolved brand context. `event_id` and `venue_id` are plain ULIDs
     * (ADR-0014) — Event owns both, so this creates no row there, and
     * `venue_id` stays null because it is optional display context.
     *
     * The name is unique per *event*, not globally, so it carries a random
     * suffix rather than drawing on `fake()->unique()`'s pool — a long suite
     * creating hundreds of locations would exhaust that.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_id' => (string) Str::ulid(),
            'venue_id' => null,
            'name' => 'Location '.Str::upper(Str::random(6)),
            'position' => 0,
        ];
    }

    public function forEvent(string $eventId): static
    {
        return $this->state(fn (array $attributes): array => [
            'event_id' => $eventId,
        ]);
    }
}
