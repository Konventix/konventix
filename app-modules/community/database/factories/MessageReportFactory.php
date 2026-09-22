<?php

declare(strict_types=1);

namespace Konventix\Community\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Konventix\Community\Enums\ReportState;
use Konventix\Community\Models\Message;
use Konventix\Community\Models\MessageReport;

/**
 * @extends Factory<MessageReport>
 */
class MessageReportFactory extends Factory
{
    /** @var class-string<MessageReport> */
    protected $model = MessageReport::class;

    /**
     * `reporter_user_id` is a plain ULID, no cross-module factory
     * (ADR-0014) — a caller arranging a specific reporter overrides it with
     * a real account id.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'message_id' => Message::factory(),
            'reporter_user_id' => (string) Str::ulid(),
            'note' => fake()->optional()->sentence(),
            'state' => ReportState::Open,
        ];
    }
}
