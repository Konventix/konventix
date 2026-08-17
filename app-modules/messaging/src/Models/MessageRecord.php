<?php

declare(strict_types=1);

namespace Konventix\Messaging\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Konventix\Messaging\Database\Factories\MessageRecordFactory;
use Konventix\Messaging\Enums\Channel;
use Konventix\Messaging\Enums\MessageStatus;
use Konventix\Platform\Models\Concerns\BelongsToBrand;

/**
 * One delivery-log row per accepted envelope (ADR-0041): the answer to
 * "did they get the mail". Infrastructure, not a domain record — shaped
 * for the D-CPL-01 twelve-month purge from day one.
 *
 * The primary key is the envelope's own messageId, assigned at accept time
 * so the log row and every contract event share one identifier — no
 * HasUlids, nothing generates here. Bodies are stored only for template
 * keys flagged logBodies (D-MSG-07 hybrid); the typed payload is always
 * kept.
 *
 * @property string $id
 * @property string $brand_id
 * @property string|null $recipient_id
 * @property string $recipient_email
 * @property Channel $channel
 * @property string $template_key
 * @property string|null $topic_key
 * @property MessageStatus $status
 * @property array<string, mixed> $payload
 * @property string|null $rendered_subject
 * @property string|null $rendered_body
 * @property int $attempts
 * @property string|null $last_error
 * @property string|null $provider_message_id
 * @property bool $urgent
 * @property Carbon|null $hold_until
 * @property Carbon $queued_at
 * @property Carbon|null $sent_at
 * @property Carbon|null $failed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class MessageRecord extends Model
{
    use BelongsToBrand;

    /** @use HasFactory<MessageRecordFactory> */
    use HasFactory;

    protected $table = 'messaging_messages';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'recipient_id',
        'recipient_email',
        'channel',
        'template_key',
        'topic_key',
        'status',
        'payload',
        'rendered_subject',
        'rendered_body',
        'attempts',
        'last_error',
        'provider_message_id',
        'urgent',
        'hold_until',
        'queued_at',
        'sent_at',
        'failed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => Channel::class,
            'status' => MessageStatus::class,
            'payload' => 'array',
            'attempts' => 'integer',
            'urgent' => 'boolean',
            'hold_until' => 'datetime',
            'queued_at' => 'datetime',
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    protected static function newFactory(): MessageRecordFactory
    {
        return MessageRecordFactory::new();
    }
}
