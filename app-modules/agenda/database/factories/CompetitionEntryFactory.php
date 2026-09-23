<?php

declare(strict_types=1);

namespace Konventix\Agenda\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Konventix\Agenda\Models\CompetitionEntry;

/**
 * @extends Factory<CompetitionEntry>
 */
class CompetitionEntryFactory extends Factory
{
    /** @var class-string<CompetitionEntry> */
    protected $model = CompetitionEntry::class;

    /**
     * No `brand_id` here on purpose: BelongsToBrand stamps it from the
     * resolved brand context. `event_id` and `competition_id` are plain
     * ULIDs (ADR-0014) — Event and Competition are other modules, so this
     * deliberately creates nothing there.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_id' => (string) Str::ulid(),
            'competition_id' => (string) Str::ulid(),
            'track_id' => null,
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
