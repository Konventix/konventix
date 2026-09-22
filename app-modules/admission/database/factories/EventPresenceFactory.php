<?php

declare(strict_types=1);

namespace Konventix\Admission\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Konventix\Admission\Models\Admission;
use Konventix\Admission\Models\EventPresence;

/**
 * @extends Factory<EventPresence>
 */
class EventPresenceFactory extends Factory
{
    /** @var class-string<EventPresence> */
    protected $model = EventPresence::class;

    /**
     * No `brand_id` here on purpose: BelongsToBrand stamps it from the
     * resolved brand context. `event_id`/`user_id` are plain ULIDs
     * (ADR-0014); only `admission_id` is a real row, because the table
     * holds a foreign key onto `admissions`.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_id' => (string) Str::ulid(),
            'user_id' => (string) Str::ulid(),
            'admission_id' => Admission::factory(),
            'first_admitted_at' => now(),
            'last_check_in_at' => now(),
            'last_direction' => 'in',
            'window_ends_at' => null,
            'hub_access' => true,
        ];
    }
}
