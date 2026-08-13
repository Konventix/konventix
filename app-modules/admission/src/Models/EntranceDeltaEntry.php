<?php

declare(strict_types=1);

namespace Konventix\Admission\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Konventix\Platform\Models\Concerns\BelongsToBrand;

/**
 * One entry in the PII-free delta feed (ADR-0033): an admission ID that a
 * device must now treat as revoked, or as already checked in.
 *
 * Positive decisions come from the signature; these are the negative ones a
 * signature cannot make. The row holds an **opaque admission ID and nothing
 * else** — no name, no email, not even a hash standing in for one. That is
 * what makes a stolen scanner leak nothing.
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
 * @property Carbon|null $created_at
 */
class EntranceDeltaEntry extends Model
{
    use BelongsToBrand;

    public const string KIND_REVOKED = 'revoked';

    public const string KIND_CHECKED_IN = 'checked_in';

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
    ];
}
