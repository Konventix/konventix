<?php

declare(strict_types=1);

namespace Konventix\Community\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Konventix\Platform\Models\Concerns\BelongsToBrand;

/**
 * One person's reaction to one subject — a clone of Content's
 * `ContentReaction` shape (ROADMAP slice 22). The subject is a `(module,
 * entity_type, entity_id)` triple so slice 26 can react to a gallery item
 * through the same table; today the only subject is a profile-wall comment,
 * `('community', 'comment', <comment ulid>)`.
 *
 * No factory: rows are only ever created through the react endpoint
 * (`firstOrCreate`), never arranged directly in a test.
 *
 * @property string $id
 * @property string $brand_id
 * @property string $user_id
 * @property string $module
 * @property string $entity_type
 * @property string $entity_id
 * @property Carbon|null $created_at
 */
class CommunityReaction extends Model
{
    use BelongsToBrand;
    use HasUlids;

    public const string ENTITY_COMMENT = 'comment';

    public const null UPDATED_AT = null;

    protected $fillable = ['user_id', 'module', 'entity_type', 'entity_id'];

    /**
     * @param  Builder<CommunityReaction>  $query
     * @return Builder<CommunityReaction>
     */
    public function scopeForSubject(Builder $query, string $module, string $entityType, string $entityId): Builder
    {
        return $query->where('module', $module)->where('entity_type', $entityType)->where('entity_id', $entityId);
    }

    /**
     * The honest number for a comment: what the rows say, escaping the
     * brand scope the way `ContentReaction::countFor` does.
     */
    public static function countFor(CommunityComment $comment): int
    {
        return static::withoutBrandScope()
            ->where('brand_id', $comment->brand_id)
            ->forSubject(CommunityComment::MODULE, self::ENTITY_COMMENT, $comment->getKey())
            ->count();
    }
}
