<?php

declare(strict_types=1);

namespace Konventix\Community\Models;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Konventix\Community\Database\Factories\RoomMuteFactory;
use Konventix\Platform\Models\Concerns\BelongsToBrand;

/**
 * A timed, per-room mute (design §4.3): Twitch-shaped, and deliberately so.
 * `reason` is `NOT NULL` at the database level — a silent mute reads as a
 * broken app, and the reason is shown to the muted person. `expires_at`
 * null means "for the rest of the event".
 *
 * This is a log, not current state: a person may carry several mutes over
 * an event's life, so there is no uniqueness on `(room_id, user_id)` —
 * `isActiveAt()` is how a caller asks whether a given row is still in
 * force. `room_id` is a real FK — same module (ADR-0014) — while `user_id`
 * and `muted_by_user_id` are plain ULIDs, because accounts are Identity's.
 *
 * @property string $id
 * @property string $brand_id
 * @property string $room_id
 * @property string $user_id
 * @property string $muted_by_user_id
 * @property string $reason
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class RoomMute extends Model
{
    use BelongsToBrand;

    /** @use HasFactory<RoomMuteFactory> */
    use HasFactory, HasUlids;

    protected $table = 'community_room_mutes';

    protected $fillable = [
        'room_id',
        'user_id',
        'muted_by_user_id',
        'reason',
        'expires_at',
    ];

    public function isActiveAt(CarbonImmutable $instant): bool
    {
        return $this->expires_at === null || $this->expires_at->greaterThan($instant);
    }

    /**
     * The strongest mute holding this person in this room at `$now`, or
     * null: `expires_at` null — "for the rest of the event" (spec §4.3) —
     * outranks any timed one, which is what `NULLS FIRST` buys, and a later
     * expiry outranks an earlier one. `PostMessage` refuses on it and the
     * room page tells the person about it, so both read the same row.
     *
     * `withoutBrandScope()->forBrand()` with the room's own brand rather
     * than the ambient scope: this is also reached from paths where no brand
     * is resolved, and `BrandScope` hardens such a query to zero rows
     * (ADR-0029, `.ai/rules/app-modules-src.md`) — a lookup that silently
     * read nothing would let every muted person post.
     */
    public static function strongestInForce(Room $room, string $userId, CarbonInterface $now): ?self
    {
        return self::withoutBrandScope()
            ->forBrand($room->brand_id)
            ->where('room_id', $room->id)
            ->where('user_id', $userId)
            ->inForce($now)
            ->orderByRaw('expires_at DESC NULLS FIRST')
            ->first();
    }

    /**
     * Mutes still holding at `$now`: no expiry, or one still ahead.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeInForce(Builder $query, CarbonInterface $now): Builder
    {
        return $query->where(fn (Builder $inner) => $inner->whereNull('expires_at')->orWhere('expires_at', '>', $now));
    }

    /**
     * @return BelongsTo<Room, $this>
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    protected static function newFactory(): RoomMuteFactory
    {
        return RoomMuteFactory::new();
    }
}
