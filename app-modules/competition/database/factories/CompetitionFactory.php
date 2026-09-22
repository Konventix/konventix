<?php

declare(strict_types=1);

namespace Konventix\Competition\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Konventix\Competition\Enums\CompetitionStatus;
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
     * `Published` with an open signup window by default: a competition
     * nobody can enter would make every signup test start with a ceremony.
     *
     * No `format` — it lives on the stage since design §7.1, and
     * `Stage::factory()->format(...)` is where a test says which one.
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
            'status' => CompetitionStatus::Published,
            'signup_opens_at' => now()->subDay(),
            'signup_closes_at' => now()->addWeek(),
            'starts_at' => now()->addWeeks(2),
            'published_at' => now()->subDay(),
        ];
    }

    /** Any lifecycle state, for the transition tests (design §4). */
    public function status(CompetitionStatus $status): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => $status,
            'published_at' => $status === CompetitionStatus::Draft ? null : ($attributes['published_at'] ?? now()->subDay()),
        ]);
    }

    /** `Draft`: never announced, so `published_at` is null too. */
    public function unpublished(): static
    {
        return $this->status(CompetitionStatus::Draft);
    }

    /**
     * The signup *window* has passed while the competition is still
     * `Published` — the derived-closed case of design §4, not the
     * `SignupClosed` state (that is `->status(CompetitionStatus::SignupClosed)`).
     */
    public function signupClosed(): static
    {
        return $this->state(fn (array $attributes): array => ['signup_closes_at' => now()->subHour()]);
    }

    public function forEvent(string $eventId): static
    {
        return $this->state(fn (array $attributes): array => ['event_id' => $eventId]);
    }
}
