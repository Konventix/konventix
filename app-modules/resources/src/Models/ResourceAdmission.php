<?php

declare(strict_types=1);

namespace Konventix\Resources\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Konventix\Platform\Models\Concerns\BelongsToBrand;

/**
 * An admission as Resources knows it (#292 §6.1, ADR-0004): enough to
 * decide who may seat a ticket — the holder, the buyer who manages it —
 * and whether it still stands. Maintained by `Listeners\ProjectAdmission`;
 * no factory, a test emits Admission's events rather than inventing one.
 *
 * The key is the admission's own ULID, so no `HasUlids`: this row never
 * mints an id of its own.
 *
 * @property string $id
 * @property string $brand_id
 * @property string|null $registration_id
 * @property string $event_id
 * @property string|null $holder_user_id
 * @property string|null $buyer_user_id
 * @property string $state
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ResourceAdmission extends Model
{
    use BelongsToBrand;

    public const string STATE_ISSUED = 'issued';

    public const string STATE_ASSIGNED = 'assigned';

    public const string STATE_REVOKED = 'revoked';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'registration_id', 'event_id', 'holder_user_id', 'buyer_user_id', 'state'];

    public function isRevoked(): bool
    {
        return $this->state === self::STATE_REVOKED;
    }
}
