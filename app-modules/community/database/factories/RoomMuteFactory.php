<?php

declare(strict_types=1);

namespace Konventix\Community\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Konventix\Community\Models\Room;
use Konventix\Community\Models\RoomMute;

/**
 * @extends Factory<RoomMute>
 */
class RoomMuteFactory extends Factory
{
    /** @var class-string<RoomMute> */
    protected $model = RoomMute::class;

    /**
     * `user_id`/`muted_by_user_id` are plain ULIDs, no cross-module factory
     * (ADR-0014). `reason` always has a value here — the schema's `NOT
     * NULL` is what a test overriding it to `null` proves.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'room_id' => Room::factory(),
            'user_id' => (string) Str::ulid(),
            'muted_by_user_id' => (string) Str::ulid(),
            'reason' => fake()->sentence(),
            'expires_at' => null,
        ];
    }
}
