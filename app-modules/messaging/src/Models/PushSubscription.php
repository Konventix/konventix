<?php

declare(strict_types=1);

namespace Konventix\Messaging\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Konventix\Messaging\Database\Factories\PushSubscriptionFactory;

/**
 * One web-push device subscription per (recipient, endpoint) — ADR-0041
 * point 7. Deliberately NOT `BelongsToBrand`: a browser subscription is a
 * fact about a person's device, and accounts are operator-global
 * (ADR-0016). Pruned when the push service reports the endpoint gone.
 *
 * @property string $id
 * @property string $recipient_id
 * @property string $endpoint
 * @property string $p256dh
 * @property string $auth
 * @property string $content_encoding
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PushSubscription extends Model
{
    /** @use HasFactory<PushSubscriptionFactory> */
    use HasFactory, HasUlids;

    protected $table = 'messaging_push_subscriptions';

    protected $fillable = [
        'recipient_id',
        'endpoint',
        'p256dh',
        'auth',
        'content_encoding',
    ];

    protected static function newFactory(): PushSubscriptionFactory
    {
        return PushSubscriptionFactory::new();
    }
}
