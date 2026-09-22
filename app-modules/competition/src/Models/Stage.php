<?php

declare(strict_types=1);

namespace Konventix\Competition\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Konventix\Competition\Data\LeaderboardSettings;
use Konventix\Competition\Database\Factories\StageFactory;
use Konventix\Competition\Enums\StageEntrySource;
use Konventix\Competition\Enums\StageFormat;
use Konventix\Platform\Models\Concerns\BelongsToBrand;

/**
 * One phase of a competition, running one format (ADR-0019, design §7).
 *
 * A real tournament is rarely a single bracket: a group phase feeding a
 * playoff bracket is two stages of different formats over the same entrants.
 * LanBrackets modelled this and the inventory's rename target keeps it.
 *
 * `settings` holds the format's ruleset — best_of, points, group size,
 * grand-final reset, tiebreaker — validated by the format itself rather than
 * by this model, since only the format knows which keys it honours. A
 * leaderboard stage's half of that vocabulary is typed by
 * `LeaderboardSettings` (ADR-0009), which is what `leaderboardSettings()`
 * hands back.
 *
 * `entry_source` and `advance_count` are §7.3's multi-stage model: where this
 * stage's field comes from, and how many of its entrants the **next** stage
 * takes. Modelled now, built later — only `signup` has a seed source in this
 * slice.
 *
 * @property string $id
 * @property string $brand_id
 * @property string $competition_id
 * @property string $name
 * @property string $format
 * @property int $sequence
 * @property StageEntrySource $entry_source
 * @property int|null $advance_count
 * @property array<string, mixed>|null $settings
 * @property Carbon|null $generated_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Stage extends Model
{
    use BelongsToBrand;

    /** @use HasFactory<StageFactory> */
    use HasFactory, HasUlids;

    protected $table = 'competition_stages';

    protected $fillable = [
        'competition_id', 'name', 'format', 'sequence', 'entry_source',
        'advance_count', 'settings', 'generated_at',
    ];

    public function isGenerated(): bool
    {
        return $this->generated_at !== null;
    }

    /**
     * The format as an enum, or null for a string nothing is registered
     * under. Nullable rather than throwing: `format` is a plain column so a
     * sixth format needs no migration, and a stage carrying an unknown one
     * must still be readable enough to be corrected.
     */
    public function stageFormat(): ?StageFormat
    {
        return StageFormat::tryFrom($this->format);
    }

    /**
     * Whether this stage scores entries instead of playing matches (§7.2).
     *
     * The one branch the leaderboard adds to code that already existed:
     * everything else in this module asks a registry which generator or
     * calculator a format resolves to, and a leaderboard resolves to neither.
     */
    public function isLeaderboard(): bool
    {
        return $this->format === StageFormat::Leaderboard->value;
    }

    /**
     * This stage's leaderboard ruleset, defaults filled in (ADR-0009).
     */
    public function leaderboardSettings(): LeaderboardSettings
    {
        return LeaderboardSettings::ofStageSettings($this->settings);
    }

    /**
     * @return BelongsTo<Competition, $this>
     */
    public function competition(): BelongsTo
    {
        return $this->belongsTo(Competition::class);
    }

    /**
     * @return HasMany<CompetitionMatch, $this>
     */
    public function matches(): HasMany
    {
        return $this->hasMany(CompetitionMatch::class, 'stage_id')->orderBy('round')->orderBy('position');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'sequence' => 'integer',
            'entry_source' => StageEntrySource::class,
            'advance_count' => 'integer',
            'generated_at' => 'datetime',
        ];
    }

    protected static function newFactory(): StageFactory
    {
        return StageFactory::new();
    }
}
