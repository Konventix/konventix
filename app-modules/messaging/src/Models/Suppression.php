<?php

declare(strict_types=1);

namespace Konventix\Messaging\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Konventix\Messaging\Database\Factories\SuppressionFactory;
use Konventix\Messaging\Enums\Channel;
use Konventix\Platform\Models\Concerns\BelongsToBrand;

/**
 * One suppression per (brand, channel, address-or-recipient) — per brand,
 * never global, because ADR-0028 makes reputation per sending domain.
 * Mail binds to the email address (an invoice contact has no account);
 * push and in-app bind to the recipient ULID. Lifting keeps the row as
 * history; a partial unique index allows exactly one live entry.
 *
 * @property string $id
 * @property string $brand_id
 * @property Channel $channel
 * @property string|null $email
 * @property string|null $recipient_id
 * @property string $reason_code
 * @property Carbon $suppressed_at
 * @property Carbon|null $lifted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Suppression extends Model
{
    use BelongsToBrand;

    /** @use HasFactory<SuppressionFactory> */
    use HasFactory, HasUlids;

    protected $table = 'messaging_suppressions';

    protected $fillable = [
        'channel',
        'email',
        'recipient_id',
        'reason_code',
        'suppressed_at',
        'lifted_at',
    ];

    /**
     * Whether a live suppression blocks this delivery. Escapes BrandScope
     * explicitly (ADR-0010): sends are accepted from queue workers and the
     * operator host alike, keyed by the envelope's own brand.
     */
    public static function blocks(string $brandId, Channel $channel, string $email, ?string $recipientId): bool
    {
        return self::withoutBrandScope()
            ->where('brand_id', $brandId)
            ->where('channel', $channel)
            ->whereNull('lifted_at')
            ->where(function ($query) use ($email, $recipientId): void {
                $query->where('email', $email);

                if ($recipientId !== null) {
                    $query->orWhere('recipient_id', $recipientId);
                }
            })
            ->exists();
    }

    public function isLive(): bool
    {
        return $this->lifted_at === null;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => Channel::class,
            'suppressed_at' => 'datetime',
            'lifted_at' => 'datetime',
        ];
    }

    protected static function newFactory(): SuppressionFactory
    {
        return SuppressionFactory::new();
    }
}
