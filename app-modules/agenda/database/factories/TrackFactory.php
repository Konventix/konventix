<?php

declare(strict_types=1);

namespace Konventix\Agenda\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Konventix\Agenda\Models\Track;

/**
 * @extends Factory<Track>
 */
class TrackFactory extends Factory
{
    /** @var class-string<Track> */
    protected $model = Track::class;

    /**
     * No `brand_id` here on purpose: BelongsToBrand stamps it from the
     * resolved brand context. `event_id` is a plain ULID (ADR-0014) — Event
     * is another module, so this deliberately does not create a row there.
     *
     * The name is unique per *event*, not globally, so it carries a random
     * suffix rather than drawing on `fake()->unique()`'s pool — a long suite
     * creating hundreds of tracks would exhaust that.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_id' => (string) Str::ulid(),
            'name' => 'Track '.Str::upper(Str::random(6)),
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
