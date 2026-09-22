<?php

declare(strict_types=1);

namespace Konventix\Competition\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Konventix\Competition\Database\Factories\EntrantFactory;
use Konventix\Competition\Enums\EntrantState;
use Konventix\Platform\Models\Concerns\BelongsToBrand;

/**
 * A team entered in a competition. Always a team, even for a solo player
 * (D-CMP-13) — one shape means the bracket engine never branches on whether
 * an entrant is a person or a squad.
 *
 * @property string $id
 * @property string $brand_id
 * @property string $competition_id
 * @property string $competition_team_id
 * @property EntrantState $state
 * @property int|null $seed
 * @property Carbon|null $checked_in_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Entrant extends Model
{
    use BelongsToBrand;

    /** @use HasFactory<EntrantFactory> */
    use HasFactory, HasUlids;

    protected $fillable = ['competition_id', 'competition_team_id', 'state', 'seed', 'checked_in_at'];

    /** Whether this entrant still occupies a place in the competition. */
    public function isActive(): bool
    {
        return in_array($this->state, [EntrantState::Registered, EntrantState::CheckedIn], true);
    }

    /**
     * The states that hold a slot against the participant limit (design
     * §5.4). Wider than `isActive()` on purpose: an `incomplete` roster is
     * not in the bracket and not playable, but it keeps its place until the
     * organizer fills it from the interested list or declines it — otherwise
     * the fill-up at signup close would have nothing left to fill.
     *
     * @return list<string>
     */
    public static function slotHoldingStates(): array
    {
        return [
            EntrantState::Incomplete->value,
            EntrantState::Registered->value,
            EntrantState::CheckedIn->value,
        ];
    }

    /**
     * How many entrants hold a slot in a competition — the number the
     * participant limit is measured against and the one `SignupClosed`
     * carries (design §4, §5.4).
     *
     * Escapes `BrandScope` and states the brand itself: the console runs on
     * the admin host, where the scope hardens to zero rows (ADR-0029), and a
     * relation count would re-apply it (.ai/rules/app-modules-src.md).
     */
    public static function countSlotHolders(string $brandId, string $competitionId): int
    {
        return self::withoutBrandScope()
            ->where('brand_id', $brandId)
            ->where('competition_id', $competitionId)
            ->whereIn('state', self::slotHoldingStates())
            ->count();
    }

    /**
     * @return BelongsTo<Competition, $this>
     */
    public function competition(): BelongsTo
    {
        return $this->belongsTo(Competition::class);
    }

    /**
     * @return BelongsTo<CompetitionTeam, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(CompetitionTeam::class, 'competition_team_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['state' => EntrantState::class, 'seed' => 'integer', 'checked_in_at' => 'datetime'];
    }

    protected static function newFactory(): EntrantFactory
    {
        return EntrantFactory::new();
    }
}
