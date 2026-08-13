<?php

declare(strict_types=1);

namespace Konventix\Admission\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Konventix\Admission\Database\Factories\AdmissionClaimLinkFactory;
use Konventix\Platform\Models\Concerns\BelongsToBrand;

/**
 * A shareable link that lets a not-yet-named person attach themselves to one
 * holder-less `Admission` (ADR-0032).
 *
 * ADR-0032 settles that the placeholder lives here but leaves the mechanism
 * open. This mirrors Registration's waitlist offer — a temporary signed
 * route whose signature expiry is this row's `expires_at`, so the URL and the
 * record cannot disagree about when the offer closed.
 *
 * A row rather than a bare signed admission id, because a link needs its own
 * lifecycle: revoke before use, audit who claimed which slot, and re-issue
 * without reviving a link someone forwarded months ago.
 *
 * @property string $id
 * @property string $brand_id
 * @property string $admission_id
 * @property string|null $claimed_by_user_id
 * @property string|null $intended_email
 * @property Carbon $expires_at
 * @property Carbon|null $claimed_at
 * @property Carbon|null $revoked_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AdmissionClaimLink extends Model
{
    use BelongsToBrand;

    /** @use HasFactory<AdmissionClaimLinkFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'admission_id',
        'claimed_by_user_id',
        'intended_email',
        'expires_at',
        'claimed_at',
        'revoked_at',
    ];

    /**
     * Whether this link can still be redeemed. Mirrors
     * `InvitationToken::isUsable()` — and, like it, is re-checked *after* the
     * row lock is taken, never before, or two people racing one link could
     * both pass this.
     */
    public function isClaimable(): bool
    {
        return $this->claimed_at === null
            && $this->revoked_at === null
            && $this->expires_at->isFuture();
    }

    /**
     * @return BelongsTo<Admission, $this>
     */
    public function admission(): BelongsTo
    {
        return $this->belongsTo(Admission::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'claimed_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    protected static function newFactory(): AdmissionClaimLinkFactory
    {
        return AdmissionClaimLinkFactory::new();
    }
}
