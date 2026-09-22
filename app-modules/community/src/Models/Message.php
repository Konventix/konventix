<?php

declare(strict_types=1);

namespace Konventix\Community\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Konventix\Community\Database\Factories\MessageFactory;
use Konventix\Platform\Models\Concerns\BelongsToBrand;

/**
 * One shoutbox line (design §4.2). `room_id` is a real FK — same module
 * (ADR-0014) — while `author_user_id` is a plain ULID: accounts are
 * Identity's, and display names come from `CommunityProfile`.
 *
 * Deletion is a tombstone, not Eloquent's `SoftDeletes`: a removed line
 * keeps its row, so a report naming it still resolves and the desk can see
 * what it acted on. There is deliberately no query scope hiding a
 * tombstoned row — the room's read path renders it as "Von der Orga
 * entfernt" and drops its body and author before it reaches other
 * participants; `isRemoved()` is what that rendering decision reads.
 *
 * Ordering is by `id`: ULIDs are monotonic, so this is chronological
 * without trusting a clock, and `(room_id, id)` is indexed for the "since
 * this id" range scan the realtime client and pagination need.
 *
 * @property string $id
 * @property string $brand_id
 * @property string $room_id
 * @property string $author_user_id
 * @property string $body
 * @property CarbonImmutable|null $deleted_at
 * @property string|null $deleted_by_user_id
 * @property string|null $deleted_reason
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class Message extends Model
{
    use BelongsToBrand;

    /** @use HasFactory<MessageFactory> */
    use HasFactory, HasUlids;

    protected $table = 'community_messages';

    /**
     * The tombstone triple is deliberately **not** here. `deleted_at`,
     * `deleted_by_user_id` and `deleted_reason` are tied together by no
     * database CHECK — `RemoveMessage` writing all three in one `forceFill`
     * is the only thing that keeps them consistent — so an ordinary
     * `update()` from a retention sweep, an eraser or an admin quick-action
     * could otherwise set one of them and leave a tombstone the console
     * cannot explain. Off `$fillable`, that write has to be deliberate.
     *
     * @var list<string>
     */
    protected $fillable = [
        'room_id',
        'author_user_id',
        'body',
    ];

    public function isRemoved(): bool
    {
        return $this->deleted_at !== null;
    }

    /**
     * @return BelongsTo<Room, $this>
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /**
     * @return HasMany<MessageReport, $this>
     */
    public function reports(): HasMany
    {
        return $this->hasMany(MessageReport::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'deleted_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    protected static function newFactory(): MessageFactory
    {
        return MessageFactory::new();
    }
}
