<?php

declare(strict_types=1);

namespace Konventix\Messaging\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Konventix\Messaging\Database\Factories\InboxNotificationFactory;
use Konventix\Platform\Models\Concerns\BelongsToBrand;

/**
 * One in-app inbox row per delivered in-app envelope (ADR-0041 point 6) —
 * the in-app channel's delivery *is* this row. Named `InboxNotification`
 * rather than `Notification` to stay unmistakably apart from Laravel's own
 * notification system, which this module deliberately does not use.
 *
 * @property string $id
 * @property string $brand_id
 * @property string $recipient_id
 * @property string $message_id
 * @property string|null $topic_key
 * @property string $title
 * @property string $body
 * @property string|null $url
 * @property Carbon|null $read_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class InboxNotification extends Model
{
    use BelongsToBrand;

    /** @use HasFactory<InboxNotificationFactory> */
    use HasFactory, HasUlids;

    protected $table = 'messaging_notifications';

    protected $fillable = [
        'recipient_id',
        'message_id',
        'topic_key',
        'title',
        'body',
        'url',
        'read_at',
    ];

    public function isUnread(): bool
    {
        return $this->read_at === null;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
        ];
    }

    protected static function newFactory(): InboxNotificationFactory
    {
        return InboxNotificationFactory::new();
    }
}
