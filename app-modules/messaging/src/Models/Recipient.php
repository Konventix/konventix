<?php

declare(strict_types=1);

namespace Konventix\Messaging\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Messaging's recipient read model (SPEC.md "Consumes"): one row per known
 * account, keyed by the user's own ULID, projected from Identity's events
 * by `Listeners\ProjectRecipient` — idempotent, replayable, rebuildable
 * from a replay of those events (ADR-0004).
 *
 * Deliberately NOT `BelongsToBrand` (accounts are operator-global,
 * ADR-0016) and NOT `HasUlids` (the key is assigned from the event payload,
 * never generated). No factory: rows exist only through the projection —
 * tests emit the Identity event instead.
 *
 * @property string $id
 * @property string $email
 * @property string $display_name
 * @property string|null $locale null follows the brand (ADR-0068 §4)
 * @property string|null $timezone
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Recipient extends Model
{
    protected $table = 'messaging_recipients';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'email', 'display_name', 'locale', 'timezone'];
}
