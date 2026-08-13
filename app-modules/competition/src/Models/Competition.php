<?php

declare(strict_types=1);

namespace Konventix\Competition\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Konventix\Competition\Database\Factories\CompetitionFactory;
use Konventix\Platform\Models\Concerns\BelongsToBrand;

/**
 * One contest at one event, running one discipline in one format.
 *
 * `format` is a plain string rather than an enum column on purpose: the five
 * format engines arrive with ADR-0019's absorption and register themselves,
 * so adding a sixth must not require a migration.
 *
 * @property string $id
 * @property string $brand_id
 * @property string $discipline_id
 * @property string $event_id
 * @property string $name
 * @property string $slug
 * @property string $format
 * @property Carbon|null $signup_opens_at
 * @property Carbon|null $signup_closes_at
 * @property Carbon|null $published_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Competition extends Model
{
    use BelongsToBrand;

    /** @use HasFactory<CompetitionFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'discipline_id', 'event_id', 'name', 'slug', 'format',
        'signup_opens_at', 'signup_closes_at', 'published_at',
    ];

    /**
     * Whether signup is open right now. An unset window means "no window",
     * not "closed" — an organizer who never set dates wants people to be able
     * to sign up, not a silently shut door.
     */
    public function signupIsOpen(?CarbonInterface $now = null): bool
    {
        $now ??= now();

        if ($this->published_at === null) {
            return false;
        }

        if ($this->signup_opens_at !== null && $now->lessThan($this->signup_opens_at)) {
            return false;
        }

        return $this->signup_closes_at === null || $now->lessThan($this->signup_closes_at);
    }

    /**
     * @return BelongsTo<Discipline, $this>
     */
    public function discipline(): BelongsTo
    {
        return $this->belongsTo(Discipline::class);
    }

    /**
     * @return HasMany<Entrant, $this>
     */
    public function entrants(): HasMany
    {
        return $this->hasMany(Entrant::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'signup_opens_at' => 'datetime',
            'signup_closes_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    protected static function newFactory(): CompetitionFactory
    {
        return CompetitionFactory::new();
    }
}
