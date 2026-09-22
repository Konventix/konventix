<?php

declare(strict_types=1);

namespace Konventix\Community\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Konventix\Community\Models\Message;
use Konventix\Community\Models\Room;

/**
 * @extends Factory<Message>
 */
class MessageFactory extends Factory
{
    /** @var class-string<Message> */
    protected $model = Message::class;

    /**
     * `author_user_id` is a plain ULID, no cross-module factory (ADR-0014,
     * `CommunityCommentFactory` precedent) — a caller arranging a specific
     * author overrides it with a real account id.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'room_id' => Room::factory(),
            'author_user_id' => (string) Str::ulid(),
            'body' => fake()->sentence(),
        ];
    }
}
