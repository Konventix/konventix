<?php

declare(strict_types=1);

namespace Konventix\Competition\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Konventix\Platform\Models\Concerns\BelongsToBrand;

/**
 * "I would play this" (design §5.3).
 *
 * The list is **public** on the competition page next to the entered teams,
 * because its whole purpose is that people find each other: three players
 * short of a 5v5 have no other way to discover the two who are also looking.
 * It is also the seed of the later per-competition chat (#116), whose
 * audience is "interested + entered".
 *
 * A row disappears the moment its owner becomes a member of an entrant in
 * that competition — somebody already on a team is no longer looking — and
 * survives signup close, because §5.4's fill-up reads exactly this list.
 *
 * `user_id` is a plain ULID; Identity owns users (ADR-0014).
 *
 * @property string $id
 * @property string $brand_id
 * @property string $competition_id
 * @property string $user_id
 * @property string|null $note
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class CompetitionInterest extends Model
{
    use BelongsToBrand;
    use HasUlids;

    protected $table = 'competition_interests';

    protected $fillable = ['competition_id', 'user_id', 'note'];

    /**
     * Everyone who registered interest in a competition, oldest first — the
     * order §5.4's fill-up offers them in, so the person who spoke up first
     * is the first one asked.
     *
     * `withoutBrandScope()` is explicit: the console runs on the admin host,
     * where the scope hardens to zero rows (ADR-0029).
     *
     * Returned as a base `Collection`: this is a read list for a page and a
     * fill-up, never a set of models something is about to save.
     *
     * @return Collection<int, static>
     */
    public static function forCompetition(string $brandId, string $competitionId): Collection
    {
        return self::withoutBrandScope()
            ->where('brand_id', $brandId)
            ->where('competition_id', $competitionId)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->toBase();
    }

    /**
     * @return BelongsTo<Competition, $this>
     */
    public function competition(): BelongsTo
    {
        return $this->belongsTo(Competition::class);
    }
}
