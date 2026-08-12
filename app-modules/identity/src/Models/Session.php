<?php

declare(strict_types=1);

namespace Konventix\Identity\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A read/write model over the framework's own `sessions` table (database
 * session driver), which predates this module's ULID convention — its
 * primary key is the opaque session id Laravel's session handler generates,
 * not a ULID, the same pre-existing exception `password_reset_tokens` already
 * is (ADR-0014 governs models this module creates going forward, not the
 * columns Laravel's session handler owns).
 *
 * No `BelongsToBrand`: the `sessions` table carries no `brand_id` column.
 * Brand scoping happens one layer up, at the cookie (`ResolveBrand` sets a
 * brand-specific `session.cookie` name), not at the row — see
 * `SessionController` for what that means for "this brand's sessions".
 *
 * @property string $id
 * @property string|null $user_id
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property string $payload
 * @property int $last_activity
 */
#[Fillable(['id', 'user_id', 'ip_address', 'user_agent', 'payload', 'last_activity'])]
class Session extends Model
{
    protected $table = 'sessions';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_activity' => 'integer',
        ];
    }
}
