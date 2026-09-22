<?php

declare(strict_types=1);

namespace Konventix\Agenda\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Konventix\Agenda\Database\Factories\AgendaItemFactory;
use Konventix\Agenda\Enums\ItemState;
use Konventix\Platform\Models\Concerns\BelongsToBrand;

/**
 * One scheduled programme entry (design §4.2) — a talk, a workshop, the
 * opening, the band.
 *
 * D-AGD-04: an item carries a start and/or an end, never neither. Three
 * shapes are legal — start + end (a block with a duration), start only
 * ("Einlass ab 12:00"), end only ("bis 08:00") — and the check constraint
 * `agenda_items_has_a_time` is what makes that true for every writer,
 * rather than a rule each Action has to remember.
 *
 * `event_id` is a plain ULID with no FK (ADR-0014); `track_id` and
 * `location_id` are real foreign keys because Track and Location belong to
 * this module, and both are `nullOnDelete` so a vanished track or place
 * leaves the item placeless instead of deleting it. `speaker` is a plain
 * value: an external speaker needs no account.
 *
 * @property string $id
 * @property string $brand_id
 * @property string $event_id
 * @property string|null $track_id
 * @property string|null $location_id
 * @property string $title
 * @property string|null $description
 * @property CarbonImmutable|null $starts_at
 * @property CarbonImmutable|null $ends_at
 * @property string|null $location_note
 * @property string|null $speaker
 * @property ItemState $state
 * @property string|null $cancelled_reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Track|null $track
 * @property-read Location|null $location
 */
class AgendaItem extends Model
{
    use BelongsToBrand;

    /** @use HasFactory<AgendaItemFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'event_id',
        'track_id',
        'location_id',
        'title',
        'description',
        'starts_at',
        'ends_at',
        'location_note',
        'speaker',
        'state',
        'cancelled_reason',
    ];

    /**
     * What the public programme page shows. D-AGD-08: a cancelled item is
     * not a deleted one — it stays on the page, struck through, so that
     * somebody who planned an afternoon around it is actually told.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeVisible(Builder $query): Builder
    {
        return $query->whereIn('state', [ItemState::Published, ItemState::Cancelled]);
    }

    /**
     * @return BelongsTo<Track, $this>
     */
    public function track(): BelongsTo
    {
        return $this->belongsTo(Track::class);
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'state' => ItemState::class,
        ];
    }

    protected static function newFactory(): AgendaItemFactory
    {
        return AgendaItemFactory::new();
    }
}
