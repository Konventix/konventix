<?php

declare(strict_types=1);

namespace Konventix\Content\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Konventix\Content\Database\Factories\ContentCommentFactory;
use Konventix\Content\Enums\CommentState;
use Konventix\Platform\Models\Concerns\BelongsToBrand;

/**
 * A comment on an entry.
 *
 * `author_user_id` is not nullable, and that is the decision rather than an
 * oversight: comments require an account (D-BATCH-7, Round 7), so there is no
 * pseudonymous author to model.
 *
 * `reaction_count` is recounted from `content_reactions` by the reaction
 * Actions and the GDPR handler — never incremented.
 *
 * @property string $id
 * @property string $brand_id
 * @property string $content_entry_id
 * @property string $author_user_id
 * @property string $body
 * @property CommentState $state
 * @property string|null $moderated_by_user_id
 * @property Carbon|null $moderated_at
 * @property int $report_count
 * @property int $reaction_count
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ContentComment extends Model
{
    use BelongsToBrand;

    /** @use HasFactory<ContentCommentFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'content_entry_id',
        'author_user_id',
        'body',
        'state',
        'moderated_by_user_id',
        'moderated_at',
        'report_count',
        'reaction_count',
    ];

    public function isVisible(): bool
    {
        return $this->state === CommentState::Approved;
    }

    /**
     * @return BelongsTo<ContentEntry, $this>
     */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(ContentEntry::class, 'content_entry_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'state' => CommentState::class,
            'moderated_at' => 'datetime',
            'report_count' => 'integer',
            'reaction_count' => 'integer',
        ];
    }

    protected static function newFactory(): ContentCommentFactory
    {
        return ContentCommentFactory::new();
    }
}
