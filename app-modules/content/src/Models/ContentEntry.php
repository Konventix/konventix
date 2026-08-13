<?php

declare(strict_types=1);

namespace Konventix\Content\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Konventix\Content\Database\Factories\ContentEntryFactory;
use Konventix\Content\Enums\Audience;
use Konventix\Content\Enums\ContentType;
use Konventix\Content\Enums\PublicationState;
use Konventix\Content\Enums\Urgency;
use Konventix\Platform\Models\Concerns\BelongsToBrand;

/**
 * One publishable thing — news, a page, or an announcement — distinguished by
 * a typed discriminator rather than three near-identical tables.
 *
 * @property string $id
 * @property string $brand_id
 * @property string|null $event_id
 * @property string|null $author_user_id
 * @property ContentType $type
 * @property string $slug
 * @property string $title
 * @property string|null $teaser
 * @property string|null $body
 * @property PublicationState $state
 * @property Audience $audience
 * @property Urgency|null $urgency
 * @property Carbon|null $published_at
 * @property bool $comments_enabled
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ContentEntry extends Model
{
    use BelongsToBrand;

    /** @use HasFactory<ContentEntryFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'event_id',
        'author_user_id',
        'type',
        'slug',
        'title',
        'teaser',
        'body',
        'state',
        'audience',
        'urgency',
        'published_at',
        'comments_enabled',
    ];

    /**
     * Live right now. Asks the state *and* the date: a scheduled entry whose
     * moment has passed is not live until `PublishScheduledEntries` promotes
     * it, so that unpublishing one before its date actually prevents it
     * appearing.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeLive(Builder $query, ?CarbonInterface $now = null): Builder
    {
        return $query->where('state', PublicationState::Published)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', $now ?? now());
    }

    public function isLive(?CarbonInterface $now = null): bool
    {
        return $this->state === PublicationState::Published
            && $this->published_at !== null
            && $this->published_at->lessThanOrEqualTo($now ?? now());
    }

    /**
     * @return HasMany<ContentComment, $this>
     */
    public function comments(): HasMany
    {
        return $this->hasMany(ContentComment::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ContentType::class,
            'state' => PublicationState::class,
            'audience' => Audience::class,
            'urgency' => Urgency::class,
            'published_at' => 'datetime',
            'comments_enabled' => 'boolean',
        ];
    }

    protected static function newFactory(): ContentEntryFactory
    {
        return ContentEntryFactory::new();
    }
}
