<?php

declare(strict_types=1);

namespace Konventix\Admission\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Konventix\Admission\Database\Factories\EntranceSigningKeyFactory;
use Konventix\Platform\Models\Concerns\BelongsToBrand;

/**
 * One Ed25519 keypair for signing KVX1 admission tokens (ADR-0033).
 *
 * Signing and verification have different lifetimes. `retired_at` stops a key
 * being chosen to sign new tokens; it keeps verifying until `expires_at`,
 * because tokens already in attendees' wallets were signed by it and must
 * keep working. Publishing "active + retired-unexpired" is exactly what
 * LanCore's signing-keys endpoint did, and the one part of it worth porting
 * as-is.
 *
 * @property string $id
 * @property string $brand_id
 * @property string $kid
 * @property string $public_key raw 32-byte Ed25519 public key, base64
 * @property string $private_key raw 64-byte Ed25519 secret key, base64, encrypted at rest
 * @property Carbon|null $activated_at
 * @property Carbon|null $retired_at
 * @property Carbon $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class EntranceSigningKey extends Model
{
    use BelongsToBrand;

    /** @use HasFactory<EntranceSigningKeyFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'kid',
        'public_key',
        'private_key',
        'activated_at',
        'retired_at',
        'expires_at',
    ];

    /**
     * Keys a device should accept signatures from: currently signing, plus
     * retired ones whose tokens can still be in circulation.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopePublishable(Builder $query, ?Carbon $now = null): Builder
    {
        $now ??= Carbon::now();

        return $query->whereNotNull('activated_at')
            ->where('activated_at', '<=', $now)
            ->where('expires_at', '>', $now);
    }

    /**
     * Whether this key may sign a *new* token — active and not retired.
     * Verification deliberately asks a weaker question.
     */
    public function canSign(?Carbon $now = null): bool
    {
        $now ??= Carbon::now();

        return $this->activated_at !== null
            && $this->activated_at->lessThanOrEqualTo($now)
            && $this->retired_at === null
            && $this->expires_at->greaterThan($now);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'private_key' => 'encrypted',
            'activated_at' => 'datetime',
            'retired_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    protected static function newFactory(): EntranceSigningKeyFactory
    {
        return EntranceSigningKeyFactory::new();
    }
}
