<?php

declare(strict_types=1);

namespace Konventix\Admission\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Konventix\Platform\Models\Concerns\BelongsToBrand;

/**
 * One entry in the PII-free delta feed (ADR-0033): an admission ID that a
 * device must now treat as revoked, as already checked in, or — since
 * design doc §5 (Task 6) — as holding a superseded token.
 *
 * Positive decisions come from the signature; these are the negative ones a
 * signature cannot make. `revoked` and `checked_in` carry nothing but the
 * admission ID; `token_superseded` carries `payload = ['hash' => ...]`, the
 * admission's new `token_nonce_hash`, because a device cannot decide "is
 * the token I just scanned the current one" from an opaque ID alone — it
 * needs the hash to compare a scanned token's nonce against. That payload
 * is still PII-free: a hash of a nonce says nothing about a person.
 *
 * Append-only, keyed by a monotonic `sequence` the client walks. Not a ULID
 * primary key, unlike every other model here (ADR-0014): this is an ordering
 * a client resumes from, not an identity anything references.
 *
 * @property int $sequence
 * @property string $brand_id
 * @property string $event_id
 * @property string $admission_id
 * @property string $kind
 * @property array<string, mixed>|null $payload
 * @property Carbon|null $created_at
 */
class EntranceDeltaEntry extends Model
{
    use BelongsToBrand;

    public const string KIND_REVOKED = 'revoked';

    public const string KIND_CHECKED_IN = 'checked_in';

    public const string KIND_TOKEN_SUPERSEDED = 'token_superseded';

    protected $primaryKey = 'sequence';

    /**
     * The one place an auto-incrementing key is correct in this codebase —
     * see the class docblock.
     */
    public $incrementing = true;

    protected $keyType = 'int';

    /**
     * Append-only: there is no `updated_at` to maintain.
     */
    public const ?string UPDATED_AT = null;

    protected $fillable = [
        'event_id',
        'admission_id',
        'kind',
        'payload',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
        ];
    }
}
