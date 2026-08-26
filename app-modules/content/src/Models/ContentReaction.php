<?php

declare(strict_types=1);

namespace Konventix\Content\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Konventix\Content\Database\Factories\ContentReactionFactory;
use Konventix\Platform\Models\Concerns\BelongsToBrand;

/**
 * One person's reaction to one subject (ROADMAP slice 22). The subject is a
 * `(module, entity_type, entity_id)` triple so slice 26 can react to a
 * gallery item through the same table; today the only subject is a comment.
 *
 * @property string $id
 * @property string $brand_id
 * @property string $user_id
 * @property string $module
 * @property string $entity_type
 * @property string $entity_id
 * @property Carbon|null $created_at
 */
class ContentReaction extends Model
{
    use BelongsToBrand;

    /** @use HasFactory<ContentReactionFactory> */
    use HasFactory, HasUlids;

    public const string MODULE_CONTENT = 'content';

    public const string ENTITY_COMMENT = 'comment';

    public const null UPDATED_AT = null;

    protected $fillable = ['user_id', 'module', 'entity_type', 'entity_id'];

    /**
     * @param  Builder<ContentReaction>  $query
     * @return Builder<ContentReaction>
     */
    public function scopeForSubject(Builder $query, string $module, string $entityType, string $entityId): Builder
    {
        return $query->where('module', $module)->where('entity_type', $entityType)->where('entity_id', $entityId);
    }

    /**
     * The honest number for a comment: what the rows say, escaping the brand
     * scope because callers (the GDPR handler) may run off any host.
     */
    public static function countFor(ContentComment $comment): int
    {
        return static::withoutBrandScope()
            ->where('brand_id', $comment->brand_id)
            ->forSubject(self::MODULE_CONTENT, self::ENTITY_COMMENT, $comment->getKey())
            ->count();
    }

    protected static function newFactory(): ContentReactionFactory
    {
        return ContentReactionFactory::new();
    }
}
