<?php

declare(strict_types=1);

namespace Konventix\Community\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Konventix\Community\Database\Factories\CommunityCommentFactory;
use Konventix\Platform\Models\Concerns\BelongsToBrand;

/**
 * A comment on a profile-wall subject (Task 9). The subject is a `(module,
 * entity_type, entity_id)` triple rather than a hard foreign key to
 * `community_profiles` — the shape `content_reactions` pioneered (ROADMAP
 * slice 22) — so slice 26's gallery items can reuse this table without a
 * migration; today the only subject is `('community', 'profile', <profile
 * user ulid>)`.
 *
 * `author_user_id` is a plain ULID, no FK (ADR-0014): accounts are
 * Identity's. `brand_id` is written ONLY by `BelongsToBrand`'s
 * ambient-context `creating` hook — it is deliberately not in `$fillable`.
 *
 * `reaction_count` is recounted from `community_reactions` by the reaction
 * endpoints — never incremented (Content's `content_comments.reaction_count`
 * precedent).
 *
 * @property string $id
 * @property string $brand_id
 * @property string $module
 * @property string $entity_type
 * @property string $entity_id
 * @property string $author_user_id
 * @property string $body
 * @property int $reaction_count
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class CommunityComment extends Model
{
    use BelongsToBrand;

    /** @use HasFactory<CommunityCommentFactory> */
    use HasFactory, HasUlids;

    public const string MODULE = 'community';

    public const string ENTITY_PROFILE = 'profile';

    protected $fillable = ['module', 'entity_type', 'entity_id', 'author_user_id', 'body'];

    /**
     * @param  Builder<CommunityComment>  $query
     * @return Builder<CommunityComment>
     */
    public function scopeForSubject(Builder $query, string $module, string $entityType, string $entityId): Builder
    {
        return $query->where('module', $module)->where('entity_type', $entityType)->where('entity_id', $entityId);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'reaction_count' => 'integer',
        ];
    }

    protected static function newFactory(): CommunityCommentFactory
    {
        return CommunityCommentFactory::new();
    }
}
