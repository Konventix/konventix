<?php

declare(strict_types=1);

namespace Konventix\Support\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Konventix\Support\Enums\MessageVisibility;
use Konventix\Support\Models\SupportRequest;
use Konventix\Support\Models\SupportRequestMessage;

/**
 * @extends Factory<SupportRequestMessage>
 */
class SupportRequestMessageFactory extends Factory
{
    /** @var class-string<SupportRequestMessage> */
    protected $model = SupportRequestMessage::class;

    /**
     * External by default: the common row is a reply the reporter reads,
     * and a test that wants a staff note says so.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'support_request_id' => SupportRequest::factory(),
            'author_user_id' => null,
            'visibility' => MessageVisibility::External,
            'body' => 'Thanks for the report — we are looking at it.',
        ];
    }

    public function internal(): static
    {
        return $this->state(fn (): array => ['visibility' => MessageVisibility::Internal]);
    }

    public function byUser(string $userId): static
    {
        return $this->state(fn (): array => ['author_user_id' => $userId]);
    }

    /** The guest reporter, identified by their signed link and nothing else. */
    public function byReporter(): static
    {
        return $this->state(fn (): array => ['author_user_id' => null]);
    }
}
