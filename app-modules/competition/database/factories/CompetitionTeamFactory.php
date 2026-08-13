<?php

declare(strict_types=1);

namespace Konventix\Competition\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Konventix\Competition\Models\CompetitionTeam;

/**
 * @extends Factory<CompetitionTeam>
 */
class CompetitionTeamFactory extends Factory
{
    /** @var class-string<CompetitionTeam> */
    protected $model = CompetitionTeam::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_id' => (string) Str::ulid(),
            // Not fake()->words(): it is typed array|string whatever the flag,
            // and the unique index needs a plain string anyway.
            'name' => 'Team '.Str::upper(Str::random(6)),
        ];
    }

    public function forEvent(string $eventId): static
    {
        return $this->state(fn (array $attributes): array => ['event_id' => $eventId]);
    }
}
