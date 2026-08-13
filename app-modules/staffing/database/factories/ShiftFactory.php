<?php

declare(strict_types=1);

namespace Konventix\Staffing\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Konventix\Staffing\Models\Shift;
use Konventix\Staffing\Models\Team;

/**
 * @extends Factory<Shift>
 */
class ShiftFactory extends Factory
{
    /** @var class-string<Shift> */
    protected $model = Shift::class;

    /**
     * Published by default: an unpublished shift cannot be signed up for, so
     * an unpublished default would make every sign-up test start with a
     * ceremony.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $team = Team::factory();

        return [
            'team_id' => $team,
            'event_id' => (string) Str::ulid(),
            'title' => fake()->randomElement(['Morning door', 'Evening bar', 'Teardown']),
            'starts_at' => now()->startOfHour()->addDay(),
            'ends_at' => now()->startOfHour()->addDay()->addHours(4),
            'published_at' => now(),
        ];
    }

    public function unpublished(): static
    {
        return $this->state(fn (array $attributes): array => ['published_at' => null]);
    }

    public function forTeam(Team $team): static
    {
        return $this->state(fn (array $attributes): array => [
            'team_id' => $team->id,
            'event_id' => $team->event_id,
        ]);
    }
}
