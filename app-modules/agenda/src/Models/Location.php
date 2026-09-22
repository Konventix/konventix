<?php

declare(strict_types=1);

namespace Konventix\Agenda\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Konventix\Agenda\Database\Factories\LocationFactory;
use Konventix\Platform\Models\Concerns\BelongsToBrand;

/**
 * One of the programme's own places — "Halle 3", "Bühne", "Foyer"
 * (design §4.3).
 *
 * Agenda owns this because the platform has no room entity to borrow:
 * Resources models one bookable *seat* per row and Event's `Venue` is a
 * building, not a hall inside one. `venue_id` optionally names the Event
 * venue the place sits in, by ULID and without a foreign key (ADR-0014) —
 * display context only, nothing depends on it. `event_id` is likewise a
 * plain ULID.
 *
 * Deleting a location leaves its items with `location_id = null`.
 *
 * The timestamps are `CarbonImmutable` and not `Illuminate\Support\Carbon`:
 * `AppServiceProvider` calls `Date::use(CarbonImmutable::class)`, so that is
 * what the framework hands back — and PHPStan believes the annotation
 * rather than checking it (.ai/rules/src.md, #312).
 *
 * @property string $id
 * @property string $brand_id
 * @property string $event_id
 * @property string|null $venue_id
 * @property string $name
 * @property int $position
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class Location extends Model
{
    use BelongsToBrand;

    /** @use HasFactory<LocationFactory> */
    use HasFactory, HasUlids;

    protected $table = 'agenda_locations';

    protected $fillable = [
        'event_id',
        'venue_id',
        'name',
        'position',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
        ];
    }

    protected static function newFactory(): LocationFactory
    {
        return LocationFactory::new();
    }
}
