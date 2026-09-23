<?php

declare(strict_types=1);

namespace Konventix\Competition\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Konventix\Platform\Models\Concerns\BelongsToBrand;

/**
 * Competition's read model of what the door has seen (design §6).
 *
 * `requires_event_check_in` lets a competition demand that every roster
 * member was scanned at the event entrance before the team may be checked in
 * for the tournament. Admission owns that fact; this module may not read its
 * tables (ADR-0004), so `Listeners\ProjectAdmissionPresence` projects it here
 * from Admission's public events and `CheckInEntrant` reads **only** this
 * table.
 *
 * Deliberately not Admission's own presence table: that one was built for the
 * live hub and answers "who is inside right now?". This one answers "has the
 * door ever seen this person at this event?" and is fed from the same public
 * events rather than by reaching across the boundary.
 *
 * The key is the admission's own ULID, assigned from the payload — so no
 * `HasUlids`, this row never mints an id. No factory either: rows exist only
 * through the projection, and a test emits Admission's events instead.
 *
 * @property string $admission_id
 * @property string $brand_id
 * @property string $event_id
 * @property string|null $holder_user_id
 * @property CarbonImmutable|null $checked_in_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class AdmissionPresence extends Model
{
    use BelongsToBrand;

    protected $table = 'competition_admission_presence';

    protected $primaryKey = 'admission_id';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * `brand_id` is absent on purpose: it is never mass-assigned. The
     * projector states it with `forceFill()` from the event payload, which is
     * the only place it may come from (ADR-0010, .ai/rules/models.md).
     */
    protected $fillable = ['admission_id', 'event_id', 'holder_user_id', 'checked_in_at'];

    /**
     * Which of these people the door has seen at this event, as a set of user
     * ULIDs. One query, whatever the roster size.
     *
     * `withoutBrandScope()` and an explicit brand: this runs from the
     * operator console too, where the scope hardens every brand-owned query
     * to zero rows (ADR-0029) — and a vacuous "nobody was at the door" would
     * turn into a refusal the desk cannot argue with.
     *
     * A person may hold several admissions for one event (a replacement, a
     * second ticket); one scanned admission is enough, which is why this is a
     * set rather than a count.
     *
     * @param  list<string>  $userIds
     * @return list<string>
     */
    public static function seenAtTheDoor(string $brandId, string $eventId, array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        return array_values(array_unique(array_map(strval(...), self::withoutBrandScope()
            ->where('brand_id', $brandId)
            ->where('event_id', $eventId)
            ->whereIn('holder_user_id', array_values(array_unique($userIds)))
            ->whereNotNull('checked_in_at')
            ->pluck('holder_user_id')
            ->all())));
    }

    /**
     * The roster members the door has **not** seen, in roster order — what a
     * refusal names and what the desk list shows as "still outside".
     *
     * @param  list<string>  $userIds
     * @return list<string>
     */
    public static function missingAtTheDoor(string $brandId, string $eventId, array $userIds): array
    {
        $seen = self::seenAtTheDoor($brandId, $eventId, $userIds);

        return array_values(array_filter($userIds, fn (string $userId): bool => ! in_array($userId, $seen, true)));
    }

    /**
     * `checked_in_at` is an instant the check-in list and the tests compare;
     * without the cast it came back as the driver's string.
     */
    protected function casts(): array
    {
        return ['checked_in_at' => 'datetime'];
    }
}
