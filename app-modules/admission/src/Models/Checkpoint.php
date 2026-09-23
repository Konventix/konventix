<?php

declare(strict_types=1);

namespace Konventix\Admission\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Konventix\Admission\Database\Factories\CheckpointFactory;
use Konventix\Admission\Enums\CheckpointKind;
use Konventix\Platform\Models\Concerns\BelongsToBrand;

/**
 * A door: a named place at an event where admissions are scanned.
 *
 * **Carries no policy.** Whether a repeat scan is a conflict or a legitimate
 * re-entry is the event's decision (D-ADM-03), and a checkpoint only enforces
 * it — ADR-0033 states this outright, and the absence of a policy column here
 * is that decision made structural. If a per-door exception is ever wanted,
 * it needs a decision first, not a column.
 *
 * `kind` is not a policy either: it says whether devices scan here (`door`)
 * or whether this is the event's one console checkpoint, which the operator
 * console writes against and no device is ever enrolled at (attendee-list
 * design §3.1, §6). Anything that offers checkpoints to a device reads
 * `doors()` (#328).
 *
 * @property string $id
 * @property string $brand_id
 * @property string $event_id
 * @property string $name
 * @property CheckpointKind $kind
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Checkpoint extends Model
{
    use BelongsToBrand;

    /** @use HasFactory<CheckpointFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'event_id',
        'name',
        'kind',
    ];

    /**
     * The column's own default, restated so a freshly created row reads its
     * kind without a refresh.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'kind' => 'door',
    ];

    /**
     * Only the checkpoints a device may be enrolled at — never the console's.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeDoors(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('kind'), CheckpointKind::Door->value);
    }

    /**
     * @return HasMany<EntranceDevice, $this>
     */
    public function devices(): HasMany
    {
        return $this->hasMany(EntranceDevice::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => CheckpointKind::class,
        ];
    }

    protected static function newFactory(): CheckpointFactory
    {
        return CheckpointFactory::new();
    }
}
