<?php

declare(strict_types=1);

namespace Konventix\Community\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Konventix\Community\Database\Factories\MessageReportFactory;
use Konventix\Community\Enums\ReportState;
use Konventix\Platform\Models\Concerns\BelongsToBrand;

/**
 * One entry in the desk's queue (design §4.4). Any participant may report a
 * line once — `unique(message_id, reporter_user_id)` — and this table is
 * also the seam a later auto-moderation API feeds into (design §9,
 * D-COM-02): a classifier writes reports like any other reporter, and the
 * desk's workflow does not change.
 *
 * `message_id` is a real FK — same module (ADR-0014); a message purged by
 * the retention sweep takes its reports with it. `reporter_user_id` and
 * `resolved_by_user_id` are plain ULIDs, because accounts are Identity's.
 *
 * @property string $id
 * @property string $brand_id
 * @property string $message_id
 * @property string $reporter_user_id
 * @property string|null $note
 * @property ReportState $state
 * @property string|null $resolved_by_user_id
 * @property CarbonImmutable|null $resolved_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class MessageReport extends Model
{
    use BelongsToBrand;

    /** @use HasFactory<MessageReportFactory> */
    use HasFactory, HasUlids;

    protected $table = 'community_message_reports';

    /**
     * The resolve triple — `state`, `resolved_by_user_id`, `resolved_at` —
     * is deliberately **not** here, for the reason `Message` keeps its
     * tombstone triple out: no database CHECK ties the three together, and
     * `ResolveReport` writing all of them in one `forceFill` is the only
     * thing that does. Off `$fillable`, an ordinary `update()` from an
     * eraser, a sweep or an admin quick-action cannot resolve a report in
     * passing, or leave one resolved by nobody. `ReportMessage` sets the
     * opening `state` through `forceFill` for the same reason.
     *
     * @var list<string>
     */
    protected $fillable = [
        'message_id',
        'reporter_user_id',
        'note',
    ];

    /**
     * @return BelongsTo<Message, $this>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'state' => ReportState::class,
            'resolved_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    protected static function newFactory(): MessageReportFactory
    {
        return MessageReportFactory::new();
    }
}
