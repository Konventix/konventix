<?php

declare(strict_types=1);

namespace Konventix\Admission\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Konventix\Admission\Models\Checkpoint;

/**
 * @extends Factory<Checkpoint>
 */
class CheckpointFactory extends Factory
{
    /** @var class-string<Checkpoint> */
    protected $model = Checkpoint::class;

    /**
     * No `brand_id` here on purpose: BelongsToBrand stamps it.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_id' => (string) Str::ulid(),
            'name' => fake()->randomElement(['Main entrance', 'North gate', 'Exhibitor entrance', 'Crew door']),
        ];
    }

    public function forEvent(string $eventId): static
    {
        return $this->state(fn (array $attributes): array => [
            'event_id' => $eventId,
        ]);
    }
}
