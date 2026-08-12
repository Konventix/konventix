<?php

declare(strict_types=1);

namespace Konventix\Registration\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Konventix\Platform\Models\Concerns\BelongsToBrand;
use Konventix\Registration\Database\Factories\InvitationTokenFactory;

/**
 * An access token for `invite_only` and `link` visibility types (Round 6
 * D-COM-02: Registration owns invitation tokens — access; Commerce owns
 * vouchers — price. Exhibitor visitor codes are these, granting a free
 * type). `max_uses: null` means unlimited — that is the shareable-link
 * case; a personal invitation is `max_uses: 1`.
 *
 * @property string $id
 * @property string $brand_id
 * @property string $registration_type_id
 * @property string $token
 * @property string|null $label
 * @property int|null $max_uses
 * @property int $uses
 * @property Carbon|null $expires_at
 * @property Carbon|null $revoked_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class InvitationToken extends Model
{
    use BelongsToBrand;

    /** @use HasFactory<InvitationTokenFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'registration_type_id',
        'token',
        'label',
        'max_uses',
        'expires_at',
        'revoked_at',
    ];

    /**
     * @return BelongsTo<RegistrationType, $this>
     */
    public function registrationType(): BelongsTo
    {
        return $this->belongsTo(RegistrationType::class);
    }

    public function isUsable(): bool
    {
        if ($this->revoked_at !== null) {
            return false;
        }

        if ($this->expires_at !== null && $this->expires_at->isPast()) {
            return false;
        }

        return $this->max_uses === null || $this->uses < $this->max_uses;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'max_uses' => 'integer',
            'uses' => 'integer',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    protected static function newFactory(): InvitationTokenFactory
    {
        return InvitationTokenFactory::new();
    }
}
