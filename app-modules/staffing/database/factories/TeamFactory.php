<?php

declare(strict_types=1);

namespace Konventix\Staffing\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Konventix\Staffing\Models\Team;

/**
 * @extends Factory<Team>
 */
class TeamFactory extends Factory
{
    /** @var class-string<Team> */
    protected $model = Team::class;

    /**
     * No `brand_id` here on purpose: BelongsToBrand stamps it.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_id' => (string) Str::ulid(),
            'name' => fake()->unique()->randomElement(['Entrance', 'Bar', 'Tech', 'Cleanup']).' '.Str::random(4),
            'area' => fake()->randomElement(['front-of-house', 'back-of-house', null]),
            'requires_approval' => false,
        ];
    }

    public function requiringApproval(): static
    {
        return $this->state(fn (array $attributes): array => ['requires_approval' => true]);
    }

    public function forEvent(string $eventId): static
    {
        return $this->state(fn (array $attributes): array => ['event_id' => $eventId]);
    }
}
