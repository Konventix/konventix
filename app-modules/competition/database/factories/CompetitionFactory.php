<?php

declare(strict_types=1);

namespace Konventix\Competition\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Konventix\Competition\Models\Competition;
use Konventix\Competition\Models\Discipline;

/**
 * @extends Factory<Competition>
 */
class CompetitionFactory extends Factory
{
    /** @var class-string<Competition> */
    protected $model = Competition::class;

    /**
     * Published with an open signup window by default: a competition nobody
     * can enter would make every signup test start with a ceremony.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->randomElement(['Main Cup', 'Side Bracket', 'Open']).' '.Str::random(4);

        return [
            'discipline_id' => Discipline::factory(),
            'event_id' => (string) Str::ulid(),
            'name' => $name,
            'slug' => Str::slug($name),
            'format' => 'single_elimination',
            'signup_opens_at' => now()->subDay(),
            'signup_closes_at' => now()->addWeek(),
            'published_at' => now()->subDay(),
        ];
    }

    public function unpublished(): static
    {
        return $this->state(fn (array $attributes): array => ['published_at' => null]);
    }

    public function signupClosed(): static
    {
        return $this->state(fn (array $attributes): array => ['signup_closes_at' => now()->subHour()]);
    }

    public function forEvent(string $eventId): static
    {
        return $this->state(fn (array $attributes): array => ['event_id' => $eventId]);
    }
}
