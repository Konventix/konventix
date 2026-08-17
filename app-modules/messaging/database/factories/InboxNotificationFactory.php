<?php

declare(strict_types=1);

namespace Konventix\Messaging\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Konventix\Messaging\Models\InboxNotification;

/**
 * @extends Factory<InboxNotification>
 */
class InboxNotificationFactory extends Factory
{
    /** @var class-string<InboxNotification> */
    protected $model = InboxNotification::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'recipient_id' => (string) Str::ulid(),
            'message_id' => (string) Str::ulid(),
            'topic_key' => null,
            'title' => fake()->sentence(4),
            'body' => fake()->paragraph(),
            'url' => null,
            'read_at' => null,
        ];
    }

    public function read(): static
    {
        return $this->state(['read_at' => now()]);
    }
}
