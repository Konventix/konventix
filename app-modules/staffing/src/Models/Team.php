<?php

declare(strict_types=1);

namespace Konventix\Staffing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Konventix\Platform\Models\Concerns\BelongsToBrand;
use Konventix\Staffing\Database\Factories\TeamFactory;

/**
 * A flat team at one event, with an optional area tag (D-STF-02).
 *
 * No parent id, deliberately: nesting is the feature every roster tool grows
 * and every organizer then works around. A tag answers "which teams are
 * front-of-house" without a hierarchy nobody maintains.
 *
 * @property string $id
 * @property string $brand_id
 * @property string $event_id
 * @property string $name
 * @property string|null $area
 * @property bool $requires_approval
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Team extends Model
{
    use BelongsToBrand;

    /** @use HasFactory<TeamFactory> */
    use HasFactory, HasUlids;

    protected $fillable = ['event_id', 'name', 'area', 'requires_approval'];

    /**
     * @return HasMany<Shift, $this>
     */
    public function shifts(): HasMany
    {
        return $this->hasMany(Shift::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['requires_approval' => 'boolean'];
    }

    protected static function newFactory(): TeamFactory
    {
        return TeamFactory::new();
    }
}
