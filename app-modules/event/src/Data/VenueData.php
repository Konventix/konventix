<?php

declare(strict_types=1);

namespace Konventix\Event\Data;

use Illuminate\Validation\Rule;
use Konventix\Event\Models\Venue;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Attributes\Validation\ArrayType;
use Spatie\LaravelData\Attributes\Validation\Between;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Nullable;
use Spatie\LaravelData\Attributes\Validation\Numeric;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Attributes\Validation\Size;
use Spatie\LaravelData\Attributes\Validation\StringType;
use Spatie\LaravelData\Attributes\Validation\Ulid;
use Spatie\LaravelData\Attributes\Validation\Uppercase;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Validation\ValidationContext;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Single source of validation rules, Inertia props shape, and generated
 * TypeScript for the venue form (ADR-0009): the console's create dialog and
 * edit page both post exactly this.
 *
 * `slug` is optional on the way in — `CreateVenue`/`UpdateVenue` derive one
 * from the name when the form leaves it blank — and always set on the way
 * out (`fromModel()`). The pictures are media-library ULIDs (ADR-0035) the
 * Actions check for brand ownership; `primaryMediaId` must be one of them,
 * which the payload-aware rule below says in the form's own words before
 * `Venue`'s saving hook would silently correct it.
 */
#[TypeScript]
final class VenueData extends Data
{
    /**
     * @param  array<int, OpeningPeriodData>  $openingTimes
     * @param  array<int, VenuePlaceData>  $parkingSpots
     * @param  array<int, VenuePlaceData>  $loadingZones
     * @param  list<string>  $imageMediaIds
     */
    public function __construct(
        #[Required, StringType, Max(255)]
        public readonly string $name,

        #[Required, StringType, Max(255)]
        public readonly string $addressLine1,

        #[Nullable, StringType, Max(255)]
        public readonly ?string $addressLine2,

        #[Required, StringType, Max(20)]
        public readonly string $postalCode,

        #[Required, StringType, Max(255)]
        public readonly string $city,

        #[Required, StringType, Size(2), Uppercase]
        public readonly string $countryCode,

        #[Nullable, Numeric, Between(-90, 90)]
        public readonly ?float $latitude,

        #[Nullable, Numeric, Between(-180, 180)]
        public readonly ?float $longitude,

        #[Nullable, StringType]
        public readonly ?string $accessNotes,

        #[DataCollectionOf(OpeningPeriodData::class)]
        public readonly array $openingTimes = [],

        #[Nullable, StringType, Max(255), Regex('/^[a-z0-9]+(-[a-z0-9]+)*$/')]
        public readonly ?string $slug = null,

        #[Nullable, StringType, Max(5000)]
        public readonly ?string $description = null,

        #[Nullable, Numeric, Between(-90, 90)]
        public readonly ?float $entryLatitude = null,

        #[Nullable, Numeric, Between(-180, 180)]
        public readonly ?float $entryLongitude = null,

        #[Nullable, StringType, Max(255)]
        public readonly ?string $seatPlanReference = null,

        #[Nullable, StringType, Max(5000)]
        public readonly ?string $publicTransport = null,

        #[DataCollectionOf(VenuePlaceData::class)]
        public readonly array $parkingSpots = [],

        #[DataCollectionOf(VenuePlaceData::class)]
        public readonly array $loadingZones = [],

        #[ArrayType]
        public readonly array $imageMediaIds = [],

        #[Nullable, Ulid]
        public readonly ?string $primaryMediaId = null,
    ) {}

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(ValidationContext $context): array
    {
        /** @var array<int, mixed> $images */
        $images = is_array($context->payload['imageMediaIds'] ?? null) ? $context->payload['imageMediaIds'] : [];

        return [
            'imageMediaIds' => ['max:20'],
            'imageMediaIds.*' => ['string', 'ulid', 'distinct'],
            'parkingSpots' => ['max:20'],
            'loadingZones' => ['max:20'],
            // Membership in the list as submitted — the rule the model
            // enforces on save, said as a form error instead.
            'primaryMediaId' => [Rule::in(array_values(array_filter($images, 'is_string')))],
        ];
    }

    /**
     * Explicit projection from the model: the decimal casts hand back
     * strings, the typed casts hand back collections, and the form wants
     * floats and arrays.
     */
    public static function fromModel(Venue $venue): self
    {
        return new self(
            name: $venue->name,
            addressLine1: $venue->address_line1,
            addressLine2: $venue->address_line2,
            postalCode: $venue->postal_code,
            city: $venue->city,
            countryCode: $venue->country_code,
            latitude: self::coordinate($venue->latitude),
            longitude: self::coordinate($venue->longitude),
            accessNotes: $venue->access_notes,
            openingTimes: $venue->opening_times->all(),
            slug: $venue->slug,
            description: $venue->description,
            entryLatitude: self::coordinate($venue->entry_latitude),
            entryLongitude: self::coordinate($venue->entry_longitude),
            seatPlanReference: $venue->seat_plan_reference,
            publicTransport: $venue->public_transport,
            parkingSpots: $venue->parking_spots->all(),
            loadingZones: $venue->loading_zones->all(),
            imageMediaIds: $venue->image_media_ids,
            primaryMediaId: $venue->primary_media_id,
        );
    }

    /**
     * The model-column shape for `CreateVenue`/`UpdateVenue` — one place
     * for the camelCase→snake_case mapping. The slug is the Actions' to
     * decide (derived from the name when blank, checked for uniqueness),
     * so it is deliberately not here.
     *
     * @return array<string, mixed>
     */
    public function toModelAttributes(): array
    {
        return [
            'name' => $this->name,
            'address_line1' => $this->addressLine1,
            'address_line2' => $this->addressLine2,
            'postal_code' => $this->postalCode,
            'city' => $this->city,
            'country_code' => $this->countryCode,
            'description' => $this->description,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'entry_latitude' => $this->entryLatitude,
            'entry_longitude' => $this->entryLongitude,
            'access_notes' => $this->accessNotes,
            'seat_plan_reference' => $this->seatPlanReference,
            'public_transport' => $this->publicTransport,
            'parking_spots' => $this->parkingSpots,
            'loading_zones' => $this->loadingZones,
            'image_media_ids' => $this->imageMediaIds,
            'primary_media_id' => $this->primaryMediaId,
            'opening_times' => $this->openingTimes,
        ];
    }

    private static function coordinate(?string $value): ?float
    {
        return $value === null ? null : (float) $value;
    }
}
