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
 * This table is the gate's registration leg. Since #330 the gate has a second
 * leg, a non-revoked admission held in one's own name, which
 * `userMayEnter()` asks of `AdmissionPresence` (design §5.1, addendum of
 * 2026-09-23, amending D-CMP-05). Whether that admission was *scanned* at
 * the door is still not asked here: that stays §6's `requires_event_check_in`,
 * and Competition records a tournament check-in via `Entrant::$checked_in_at`.
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
     * The §5.1 gate: whether this user holds a confirmed registration for the
     * event, **or** a non-revoked admission in their own name for it (design
     * §5.1 addendum, #330). Every call site asks this method and nothing
     * else, so the gate has one definition.
     *
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
            ->exists()
            || AdmissionPresence::holdsAdmission($brandId, $eventId, $userId);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_confirmed' => 'boolean'];
    }
}
