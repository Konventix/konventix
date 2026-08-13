<?php

declare(strict_types=1);

namespace Konventix\Admission\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Konventix\Admission\Database\Factories\EntranceDeviceFactory;
use Konventix\Platform\Models\Concerns\BelongsToBrand;
use SensitiveParameter;

/**
 * One enrolled scanner at a checkpoint (ADR-0033).
 *
 * Holds only a hash of its bearer token; the plaintext is shown once at
 * enrolment. Revocation is per device — losing a scanner locks out one row
 * instead of forcing every device at the venue to be re-tokened, which is
 * exactly what LanCore's single shared integration token could not do.
 *
 * The device holds no attendee data at all. Its credential grants the delta
 * feed and scan submission, both of which are PII-free by construction.
 *
 * @property string $id
 * @property string $brand_id
 * @property string $checkpoint_id
 * @property string $label
 * @property string $token_hash
 * @property Carbon|null $last_seen_at
 * @property Carbon|null $revoked_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class EntranceDevice extends Model
{
    use BelongsToBrand;

    /** @use HasFactory<EntranceDeviceFactory> */
    use HasFactory, HasUlids;

    protected $table = 'entrance_devices';

    protected $fillable = [
        'checkpoint_id',
        'label',
        'token_hash',
        'last_seen_at',
        'revoked_at',
    ];

    /**
     * SHA-256 rather than a password hash: this is a high-entropy random
     * secret checked on every scan, not a human-chosen password, so the
     * stretching a slow hash buys is irrelevant while its cost is not.
     * Deterministic hashing is also what allows the single indexed lookup.
     */
    public static function hashToken(#[SensitiveParameter] string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }

    /**
     * @return BelongsTo<Checkpoint, $this>
     */
    public function checkpoint(): BelongsTo
    {
        return $this->belongsTo(Checkpoint::class);
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    protected static function newFactory(): EntranceDeviceFactory
    {
        return EntranceDeviceFactory::new();
    }
}
