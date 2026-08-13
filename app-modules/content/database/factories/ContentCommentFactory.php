<?php

declare(strict_types=1);

namespace Konventix\Content\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Konventix\Content\Enums\CommentState;
use Konventix\Content\Models\ContentComment;
use Konventix\Content\Models\ContentEntry;

/**
 * @extends Factory<ContentComment>
 */
class ContentCommentFactory extends Factory
{
    /** @var class-string<ContentComment> */
    protected $model = ContentComment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'content_entry_id' => ContentEntry::factory()->published()->withComments(),
            'author_user_id' => (string) Str::ulid(),
            'body' => fake()->sentence(),
            'state' => CommentState::Approved,
            'moderated_by_user_id' => null,
            'moderated_at' => null,
            'report_count' => 0,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes): array => ['state' => CommentState::Pending]);
    }
}
