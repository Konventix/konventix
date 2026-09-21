<?php

declare(strict_types=1);

namespace Konventix\Event\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Konventix\Event\Data\OpeningPeriodData;
use Konventix\Event\Data\VenuePlaceData;
use Konventix\Event\Database\Factories\VenueFactory;
use Konventix\Event\Models\Casts\MediaIdListCast;
use Konventix\Event\Models\Casts\OpeningTimesCast;
use Konventix\Event\Models\Casts\VenuePlacesCast;
use Konventix\Platform\Models\Concerns\BelongsToBrand;

/**
 * A brand-owned, reusable sub-concept of Event (SPEC.md "Owns" — Venue):
 * postal address, geo coordinates of the building and of its entrance,
 * access and arrival notes, opening times of the site, parking spots and
 * loading zones, and an ordered set of pictures from the brand's media
 * library (ADR-0035) with one primary. Assignable to events via the
 * `event_venues` pivot (D-EVT-15: many venues per event, one primary; zero
 * venues = online-only event).
 *
 * The `slug` is unique per brand and names the public venue page
 * (`venue.show`), which resolves only while at least one publicly visible
 * event happens here — see `isPubliclyReachable()`.
 *
 * @property-read EventVenue|null $pivot When loaded through Event::venues()
 * @property string $id
 * @property string $brand_id
 * @property string $name
 * @property string $slug
 * @property string $address_line1
 * @property string|null $address_line2
 * @property string $postal_code
 * @property string $city
 * @property string $country_code
 * @property string|null $description
 * @property string|null $latitude
 * @property string|null $longitude
 * @property string|null $entry_latitude
 * @property string|null $entry_longitude
 * @property string|null $access_notes
 * @property string|null $seat_plan_reference
 * @property string|null $public_transport
 * @property Collection<int, VenuePlaceData> $parking_spots
 * @property Collection<int, VenuePlaceData> $loading_zones
 * @property list<string> $image_media_ids
 * @property string|null $primary_media_id
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
        'slug',
        'address_line1',
        'address_line2',
        'postal_code',
        'city',
        'country_code',
        'description',
        'latitude',
        'longitude',
        'entry_latitude',
        'entry_longitude',
        'access_notes',
        'seat_plan_reference',
        'public_transport',
        'parking_spots',
        'loading_zones',
        'image_media_ids',
        'primary_media_id',
        'opening_times',
    ];

    /**
     * The primary picture must be one of the venue's pictures. Enforced on
     * every save rather than only in the form: an image removed from the
     * list takes its primary status with it, and a venue never points at a
     * picture it does not carry. The first remaining picture steps in, or
     * nothing when there are none.
     */
    protected static function booted(): void
    {
        static::saving(function (Venue $venue): void {
            $ids = $venue->image_media_ids;

            if ($venue->primary_media_id !== null && ! in_array($venue->primary_media_id, $ids, true)) {
                $venue->primary_media_id = $ids[0] ?? null;
            }
        });
    }

    /**
     * The events that happen here (D-EVT-15 pivot; the inverse of
     * `Event::venues()`).
     *
     * @return BelongsToMany<Event, $this, EventVenue>
     */
    public function events(): BelongsToMany
    {
        return $this->belongsToMany(Event::class, 'event_venues')
            ->using(EventVenue::class)
            ->withPivot('is_primary')
            ->withTimestamps();
    }

    /**
     * The picture order the public page shows: the primary first, then the
     * rest in the operator's order.
     *
     * @return list<string>
     */
    public function orderedImageMediaIds(): array
    {
        $ids = $this->image_media_ids;
        $primary = $this->primary_media_id;

        if ($primary === null || ! in_array($primary, $ids, true)) {
            return $ids;
        }

        return [$primary, ...array_values(array_filter($ids, fn (string $id): bool => $id !== $primary))];
    }

    /**
     * Whether the public venue page (`venue.show`) resolves rather than
     * 404s: a venue is only public through an event that is, so the rule
     * is `Event::isVisibleOnLanding()` over the events assigned here. A
     * venue nobody can find an event for stays a console record.
     */
    public function isPubliclyReachable(): bool
    {
        return $this->events()->visibleOnLanding()->exists();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'entry_latitude' => 'decimal:7',
            'entry_longitude' => 'decimal:7',
            'opening_times' => OpeningTimesCast::class,
            'parking_spots' => VenuePlacesCast::class,
            'loading_zones' => VenuePlacesCast::class,
            'image_media_ids' => MediaIdListCast::class,
        ];
    }

    protected static function newFactory(): VenueFactory
    {
        return VenueFactory::new();
    }
}
