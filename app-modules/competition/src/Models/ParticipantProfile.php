<?php

declare(strict_types=1);

namespace Konventix\Competition\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Competition's read model of who a person is (design §10).
 *
 * **Every page in this module renders names from here, never from Identity.**
 * Identity owns accounts and this module may not import its `User` (ADR-0004,
 * ADR-0014); the profile of record travels on `UserProfileUpdated`
 * (ADR-0051 §6) and `Listeners\ProjectParticipantProfile` writes it here.
 * An architecture test asserts the import never appears.
 *
 * Deliberately NOT `BelongsToBrand` — accounts are operator-global
 * (ADR-0016), so one display name would otherwise need a row per brand — and
 * NOT `HasUlids`: the key is the user's own ULID, assigned from the payload
 * and never generated. No factory either; rows exist only through the
 * projection, so a test emits the Identity event instead.
 *
 * @property string $user_id
 * @property string $display_name
 * @property string $username
 * @property string $visibility
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ParticipantProfile extends Model
{
    protected $table = 'competition_participant_profiles';

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['user_id', 'display_name', 'username', 'visibility'];

    /**
     * Display names for a set of user ids, keyed by id. A user with no row
     * yet — registered before this projection existed, or still travelling
     * through the queue — falls back to a dash rather than to their ULID: an
     * id on a public page is noise at best and a handle to probe at worst.
     *
     * @param  list<string>  $userIds
     * @return array<string, string>
     */
    public static function displayNamesFor(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        /** @var array<string, string> $names */
        $names = self::query()
            ->whereIn('user_id', array_values(array_unique($userIds)))
            ->pluck('display_name', 'user_id')
            ->all();

        $resolved = [];

        foreach ($userIds as $userId) {
            $resolved[$userId] = $names[$userId] ?? '—';
        }

        return $resolved;
    }

    /**
     * The console's person search (design §5.2): people the §5.1 gate lets
     * into this event's competitions — a confirmed registration in this
     * module's own read model, or since #330 a non-revoked admission in their
     * name (§5.1 addendum) — whose name or username contains `$term`, at
     * most `$limit` of them, by name.
     *
     * The two legs sit in one closure so the `orWhereIn` cannot escape into
     * the name condition that follows.
     *
     * Only somebody who could actually be added is offered: `AddTeamMember`
     * refuses anybody else, and a search that listed them would be a list of
     * refusals.
     *
     * @return Collection<int, self>
     */
    public static function searchEligible(string $brandId, string $eventId, string $term, int $limit = 10): Collection
    {
        $pattern = '%'.addcslashes($term, '%_\\').'%';

        return self::query()
            ->where(fn ($query) => $query
                ->whereIn('user_id', EligibleRegistration::withoutBrandScope()
                    ->select('user_id')
                    ->where('brand_id', $brandId)
                    ->where('event_id', $eventId)
                    ->where('is_confirmed', true))
                ->orWhereIn('user_id', AdmissionPresence::holderIdsQuery($brandId, $eventId)))
            ->where(fn ($query) => $query
                ->where('display_name', 'ilike', $pattern)
                ->orWhere('username', 'ilike', $pattern))
            ->orderBy('display_name')
            ->limit($limit)
            ->get();
    }
}
