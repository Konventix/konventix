<?php

declare(strict_types=1);

namespace Konventix\Messaging\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Konventix\Messaging\Enums\Channel;

/**
 * One row per (recipient, topic, channel) — the D-MSG-05 topic model,
 * stored in Messaging (D-MSG-06): the module that applies the rule also
 * stores it, so an unsubscribe has no consistency window. Absence of a row
 * means the topic's declared default; a row only records a deviation.
 *
 * Deliberately NOT brand-scoped: recipients are operator-global
 * (ADR-0016), so their preferences are too. No factory — rows exist only
 * through the preferences page or tests writing them directly.
 *
 * @property string $id
 * @property string $recipient_id
 * @property string $topic_key
 * @property Channel $channel
 * @property bool $enabled
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class NotificationPreference extends Model
{
    use HasUlids;

    protected $table = 'messaging_preferences';

    protected $fillable = [
        'recipient_id',
        'topic_key',
        'channel',
        'enabled',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => Channel::class,
            'enabled' => 'boolean',
        ];
    }
}
