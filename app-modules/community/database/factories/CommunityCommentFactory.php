<?php

declare(strict_types=1);

namespace Konventix\Community\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Konventix\Community\Models\CommunityComment;

/**
 * @extends Factory<CommunityComment>
 */
class CommunityCommentFactory extends Factory
{
    /** @var class-string<CommunityComment> */
    protected $model = CommunityComment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'module' => CommunityComment::MODULE,
            'entity_type' => CommunityComment::ENTITY_PROFILE,
            // Plain ULID, no cross-module factory (ADR-0004,
            // `tests/Unit/ArchitectureTest.php` forbids importing another
            // module's Eloquent model even from a factory) — the same
            // `ContentCommentFactory`/`ContentReactionFactory` precedent.
            // Callers arranging a specific author override this with a real
            // `User::factory()->create()->getKey()`.
            'entity_id' => (string) Str::ulid(),
            'author_user_id' => (string) Str::ulid(),
            'body' => fake()->sentence(),
        ];
    }
}
