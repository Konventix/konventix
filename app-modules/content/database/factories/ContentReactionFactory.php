<?php

declare(strict_types=1);

namespace Konventix\Content\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Konventix\Content\Models\ContentComment;
use Konventix\Content\Models\ContentReaction;

/**
 * @extends Factory<ContentReaction>
 */
class ContentReactionFactory extends Factory
{
    /** @var class-string<ContentReaction> */
    protected $model = ContentReaction::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => (string) Str::ulid(),
            'module' => ContentReaction::MODULE_CONTENT,
            'entity_type' => ContentReaction::ENTITY_COMMENT,
            'entity_id' => ContentComment::factory(),
        ];
    }
}
