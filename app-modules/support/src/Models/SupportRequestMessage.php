<?php

declare(strict_types=1);

namespace Konventix\Support\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Konventix\Support\Database\Factories\SupportRequestMessageFactory;
use Konventix\Support\Enums\MessageVisibility;

/**
 * One entry of a report's thread (design §4.2).
 *
 * Not brand-owned for the same reason its parent is not
 * ({@see SupportRequest}): it hangs off a request that may have no brand at
 * all. Scoping is the parent's.
 *
 * `updated_at` is deliberately absent — a support message is never edited.
 * A correction is a new message, which is also what the audit trail
 * records.
 *
 * @property string $id
 * @property string $support_request_id
 * @property string|null $author_user_id
 * @property MessageVisibility $visibility
 * @property string $body
 * @property CarbonImmutable|null $created_at
 */
class SupportRequestMessage extends Model
{
    /** @use HasFactory<SupportRequestMessageFactory> */
    use HasFactory, HasUlids;

    public const UPDATED_AT = null;

    protected $table = 'support_request_messages';

    protected $fillable = [
        'support_request_id',
        'author_user_id',
        'visibility',
        'body',
    ];

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'visibility' => MessageVisibility::class,
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<SupportRequest, $this>
     */
    public function request(): BelongsTo
    {
        return $this->belongsTo(SupportRequest::class, 'support_request_id');
    }

    /**
     * What the reporter is allowed to read (design §4.2). Every
     * reporter-facing query goes through this rather than repeating the
     * filter — an internal note reaching a reporter is the one mistake this
     * table can make.
     *
     * @param  Builder<SupportRequestMessage>  $query
     * @return Builder<SupportRequestMessage>
     */
    public function scopeVisibleToReporter(Builder $query): Builder
    {
        return $query->where('visibility', MessageVisibility::External);
    }

    protected static function newFactory(): SupportRequestMessageFactory
    {
        return SupportRequestMessageFactory::new();
    }
}
