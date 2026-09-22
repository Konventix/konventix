<?php

declare(strict_types=1);

namespace Konventix\Competition\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Konventix\Competition\Models\Entrant;
use Konventix\Competition\Models\ScoreEntry;
use Konventix\Competition\Models\Stage;

/**
 * @extends Factory<ScoreEntry>
 */
class ScoreEntryFactory extends Factory
{
    /** @var class-string<ScoreEntry> */
    protected $model = ScoreEntry::class;

    /**
     * Unverified by default, because that is what a participant's submission
     * is (design §8.3) — a factory that verified on creation would make every
     * "does it count yet" test start by undoing it.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'stage_id' => Stage::factory(),
            'entrant_id' => Entrant::factory(),
            'heat' => null,
            'value' => 0.0,
            'proof_media_id' => null,
            'submitted_by_user_id' => (string) Str::ulid(),
            'verified_at' => null,
            'verified_by_user_id' => null,
            'note' => null,
        ];
    }

    public function verified(?string $byUserId = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'verified_at' => now(),
            'verified_by_user_id' => $byUserId ?? (string) Str::ulid(),
        ]);
    }
}
