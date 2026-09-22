<?php

declare(strict_types=1);

namespace Konventix\Community\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Konventix\Community\Enums\RoomSubject;
use Konventix\Community\Models\Room;

/**
 * @extends Factory<Room>
 */
class RoomFactory extends Factory
{
    /** @var class-string<Room> */
    protected $model = Room::class;

    /**
     * No `brand_id` here on purpose: `BelongsToBrand` stamps it from the
     * resolved brand context. `subject_id` is a plain ULID (ADR-0014) — a
     * caller arranging a room for a real event overrides it with that
     * event's id.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'subject_type' => RoomSubject::Event,
            'subject_id' => (string) Str::ulid(),
        ];
    }
}
