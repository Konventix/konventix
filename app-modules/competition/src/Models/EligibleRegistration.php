<?php

declare(strict_types=1);

namespace Konventix\Competition\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Konventix\Platform\Models\Concerns\BelongsToBrand;

/**
 * Competition's read model of who may enter (D-CMP-05: "confirmed
 * Registration suffices to enter").
 *
 * Built from Registration's contract events, never a query against its tables
 * (ADR-0004, ADR-0006). Two events feed it because neither alone suffices:
 * `RegistrationSubmitted` carries the registrant's user id but not
 * confirmation, and `RegistrationConfirmed` carries confirmation but not the
 * user.
 *
 * D-CMP-05's second half — "admission possession is verified at check-in /
 * match start" — is deliberately *not* modelled here. That check belongs to
 * the door and the desk; Competition records that it happened via
 * `Entrant::$checked_in_at` and never performs it.
 *
 * @property string $id
 * @property string $brand_id
 * @property string $registration_id
 * @property string $event_id
 * @property string|null $user_id
 * @property bool $is_confirmed
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class EligibleRegistration extends Model
{
    use BelongsToBrand;
    use HasUlids;

    protected $table = 'competition_eligible_registrations';

    protected $fillable = ['registration_id', 'event_id', 'user_id', 'is_confirmed'];

    /**
     * Whether this user holds a confirmed registration for the event.
     * `withoutBrandScope()` scoped explicitly (ADR-0010): signup may run from
     * a queue worker with no ambient brand.
     */
    public static function userMayEnter(string $brandId, string $eventId, string $userId): bool
    {
        return self::withoutBrandScope()
            ->where('brand_id', $brandId)
            ->where('event_id', $eventId)
            ->where('user_id', $userId)
            ->where('is_confirmed', true)
            ->exists();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_confirmed' => 'boolean'];
    }
}
