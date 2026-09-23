<?php

declare(strict_types=1);

namespace Konventix\Competition\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Competition's read model of which provider accounts a person has linked
 * (design §5.6). A discipline's `requires_linked_provider` is checked against
 * it on entry and on every roster join.
 *
 * Identity owns the link, and this module may not read `social_identities`
 * (ADR-0004), so `Listeners\ProjectLinkedIdentity` writes the rows from
 * Identity's contract events. Nothing else writes them.
 *
 * Deliberately NOT `BelongsToBrand`: a linked Steam account is a fact about
 * the person, and accounts are operator-wide (ADR-0016). `ParticipantProfile`
 * is global for the same reason. There is no factory either, because rows
 * exist only through the projection, so a test emits the Identity event
 * instead.
 *
 * @property string $id
 * @property string $user_id
 * @property string $provider
 * @property Carbon $linked_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class LinkedIdentity extends Model
{
    use HasUlids;

    protected $table = 'competition_linked_identities';

    protected $fillable = ['user_id', 'provider', 'linked_at'];

    public static function hasLinked(string $userId, string $provider): bool
    {
        return self::query()
            ->where('user_id', $userId)
            ->where('provider', $provider)
            ->exists();
    }

    /**
     * The people among `$userIds` who have not linked `$provider`, in the
     * order they were given. One query for a whole roster.
     *
     * @param  list<string>  $userIds
     * @return list<string>
     */
    public static function missingAmong(array $userIds, string $provider): array
    {
        if ($userIds === []) {
            return [];
        }

        $linked = self::query()
            ->where('provider', $provider)
            ->whereIn('user_id', array_values(array_unique($userIds)))
            ->pluck('user_id')
            ->all();

        return array_values(array_filter(
            $userIds,
            static fn (string $userId): bool => ! in_array($userId, $linked, true),
        ));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'linked_at' => 'datetime',
        ];
    }
}
