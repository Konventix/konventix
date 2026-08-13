<?php

declare(strict_types=1);

namespace Konventix\Competition\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Konventix\Competition\Enums\DisciplineKind;
use Konventix\Competition\Models\Discipline;

/**
 * @extends Factory<Discipline>
 */
class DisciplineFactory extends Factory
{
    /** @var class-string<Discipline> */
    protected $model = Discipline::class;

    /**
     * Defaults to a **non**-video-game kind, so the common test path exercises
     * the case that needs no GameOps reference at all (ADR-0024).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->randomElement(['Pub quiz', 'Pinball', 'Chess']).' '.Str::random(4);

        return [
            'kind' => DisciplineKind::Quiz,
            'name' => $name,
            'slug' => Str::slug($name),
            'game_id' => null,
            'avg_match_minutes' => 30,
            'team_size' => 1,
            'allow_draws' => false,
        ];
    }

    public function videoGame(?string $gameId = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'kind' => DisciplineKind::VideoGame,
            'game_id' => $gameId ?? (string) Str::ulid(),
        ]);
    }

    public function teamSize(int $size): static
    {
        return $this->state(fn (array $attributes): array => ['team_size' => $size]);
    }
}
