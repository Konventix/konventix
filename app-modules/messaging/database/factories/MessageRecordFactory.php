<?php

declare(strict_types=1);

namespace Konventix\Messaging\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Konventix\Messaging\Enums\Channel;
use Konventix\Messaging\Enums\MessageStatus;
use Konventix\Messaging\Models\MessageRecord;

/**
 * @extends Factory<MessageRecord>
 */
class MessageRecordFactory extends Factory
{
    /** @var class-string<MessageRecord> */
    protected $model = MessageRecord::class;

    /**
     * No `brand_id` here on purpose: BelongsToBrand stamps it.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => Str::ulid()->toBase32(),
            'recipient_id' => null,
            'recipient_email' => fake()->safeEmail(),
            'channel' => Channel::Mail,
            'template_key' => 'test.template',
            'topic_key' => null,
            'status' => MessageStatus::Queued,
            'payload' => ['example' => 'value'],
            'urgent' => false,
            'queued_at' => now(),
        ];
    }

    public function sent(): static
    {
        return $this->state(fn (): array => [
            'status' => MessageStatus::Sent,
            'sent_at' => now(),
            'attempts' => 1,
        ]);
    }

    public function held(?string $until = null): static
    {
        return $this->state(fn (): array => [
            'status' => MessageStatus::Held,
            'hold_until' => $until ?? now()->addMinutes(30),
        ]);
    }
}
