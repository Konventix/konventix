<?php

declare(strict_types=1);

namespace Konventix\Admission\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Admission's own copy of a person's display name (ADR-0004: this module
 * may not read Identity's `User` model), projected from Identity's account
 * events by `Listeners\ProjectPerson` — idempotent, replayable, rebuildable
 * from `identity:replay-profiles`.
 *
 * Exists for the ticket's holder-name fallback (design doc §13, 2026-09-11
 * amendment): the linked holder's own display name, else "Unassigned" —
 * the design's originally-named middle fallback, the registration's
 * billing recipient, is not reachable from this module at all.
 *
 * Deliberately NOT `BelongsToBrand` (accounts are operator-global,
 * ADR-0016) and NOT `HasUlids` (the key is assigned from the event
 * payload, never generated) — same shape as Registration's `Registrant`
 * and Messaging's `Recipient`. No factory: rows exist only through the
 * projection — tests emit the Identity event instead.
 *
 * `username` is what the participant pages print (ADR-0065); the display
 * name stays for the ticket PDF and the console. Null until the replay
 * reaches a row projected before the column existed.
 *
 * @property string $id
 * @property string $display_name
 * @property string|null $username
 * @property string $email
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Person extends Model
{
    protected $table = 'admission_people';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'display_name', 'username', 'email'];
}
