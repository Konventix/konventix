<?php

declare(strict_types=1);

namespace Konventix\Competition\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Konventix\Competition\Database\Factories\StageFactory;
use Konventix\Platform\Models\Concerns\BelongsToBrand;

/**
 * One phase of a competition, running one format (ADR-0019).
 *
 * A real tournament is rarely a single bracket: a group phase feeding a
 * playoff bracket is two stages of different formats over the same entrants.
 * LanBrackets modelled this and the inventory's rename target keeps it.
 *
 * `settings` holds the format's ruleset — best_of, points, group size,
 * grand-final reset, tiebreaker — validated by the format itself rather than
 * by this model, since only the format knows which keys it honours.
 *
 * @property string $id
 * @property string $brand_id
 * @property string $competition_id
 * @property string $name
 * @property string $format
 * @property int $sequence
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

    protected $fillable = ['competition_id', 'name', 'format', 'sequence', 'settings', 'generated_at'];

    public function isGenerated(): bool
    {
        return $this->generated_at !== null;
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
        return ['settings' => 'array', 'sequence' => 'integer', 'generated_at' => 'datetime'];
    }

    protected static function newFactory(): StageFactory
    {
        return StageFactory::new();
    }
}
