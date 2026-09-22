<?php

declare(strict_types=1);

namespace Konventix\Community\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Konventix\Community\Database\Factories\RoomFactory;
use Konventix\Community\Enums\RoomSubject;
use Konventix\Platform\Models\Concerns\BelongsToBrand;

/**
 * The shoutbox's room (design §4.1, ADR-0067). First-class from the start,
 * even though 0.9.0 only ever creates an `Event` room: `subject_type` +
 * `subject_id` is a plain ULID pointer at another module's entity
 * (ADR-0014), created lazily on first read and never backfilled.
 *
 * `locked_at` holds the room open-but-read-only; `closed_at` is set by
 * `EventConcluded` and starts the 30-day retention clock. Both are
 * independent — a closed room is not necessarily locked first, and the room
 * row itself is never deleted, only its messages, mutes and reports are.
 *
 * @property string $id
 * @property string $brand_id
 * @property RoomSubject $subject_type
 * @property string $subject_id
 * @property CarbonImmutable|null $locked_at
 * @property string|null $locked_by_user_id
 * @property string|null $locked_reason
 * @property CarbonImmutable|null $closed_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class Room extends Model
{
    use BelongsToBrand;

    /** @use HasFactory<RoomFactory> */
    use HasFactory, HasUlids;

    protected $table = 'community_rooms';

    /**
     * The lock triple and `closed_at` are deliberately **not** here. No
     * database CHECK ties `locked_at`, `locked_by_user_id` and
     * `locked_reason` together — `LockRoom` and `UnlockRoom` writing all
     * three in one `forceFill` is the only thing that does — and `closed_at`
     * starts the retention clock, which is not something a mass assignment
     * should be able to set in passing. All four are written through
     * `forceFill` (or, in tests, an unguarded factory).
     *
     * @var list<string>
     */
    protected $fillable = [
        'subject_type',
        'subject_id',
    ];

    /**
     * @return HasMany<Message, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /**
     * @return HasMany<RoomMute, $this>
     */
    public function mutes(): HasMany
    {
        return $this->hasMany(RoomMute::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'subject_type' => RoomSubject::class,
            'locked_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    protected static function newFactory(): RoomFactory
    {
        return RoomFactory::new();
    }
}
