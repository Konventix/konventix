<?php

declare(strict_types=1);

namespace Konventix\Event\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Konventix\Event\Data\OpeningPeriodData;
use Konventix\Event\Database\Factories\VenueFactory;
use Konventix\Event\Models\Casts\OpeningTimesCast;
use Konventix\Platform\Models\Concerns\BelongsToBrand;

/**
 * A brand-owned, reusable sub-concept of Event (SPEC.md "Owns" — Venue):
 * postal address, geo coordinates, access and arrival notes, opening times of
 * the site. Assignable to an event via `Event::$venue_id`, which is nullable
 * — multi-venue and purely online events stay an open question (SPEC.md
 * "Open questions"), so a venue is optional rather than mandatory.
 *
 * @property string $id
 * @property string $brand_id
 * @property string $name
 * @property string $address_line1
 * @property string|null $address_line2
 * @property string $postal_code
 * @property string $city
 * @property string $country_code
 * @property string|null $latitude
 * @property string|null $longitude
 * @property string|null $access_notes
 * @property Collection<int, OpeningPeriodData> $opening_times
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Venue extends Model
{
    use BelongsToBrand;

    /** @use HasFactory<VenueFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'name',
        'address_line1',
        'address_line2',
        'postal_code',
        'city',
        'country_code',
        'latitude',
        'longitude',
        'access_notes',
        'opening_times',
    ];

    /**
     * @return HasMany<Event, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'opening_times' => OpeningTimesCast::class,
        ];
    }

    protected static function newFactory(): VenueFactory
    {
        return VenueFactory::new();
    }
}
