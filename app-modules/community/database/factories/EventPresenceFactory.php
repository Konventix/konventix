<?php

declare(strict_types=1);

namespace Konventix\Community\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Konventix\Community\Models\EventPresence;

/**
 * @extends Factory<EventPresence>
 */
class EventPresenceFactory extends Factory
{
    /** @var class-string<EventPresence> */
    protected $model = EventPresence::class;

    /**
     * No `brand_id` here on purpose: `BelongsToBrand` stamps it from the
     * resolved brand context. `event_id`/`user_id` are plain ULIDs
     * (ADR-0014) — a caller arranging a row for a real event/user overrides
     * them.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_id' => (string) Str::ulid(),
            'user_id' => (string) Str::ulid(),
            'checked_in' => true,
            'last_direction' => 'in',
        ];
    }
}
