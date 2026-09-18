<?php

declare(strict_types=1);

namespace Konventix\Event\Data;

use Konventix\Event\Enums\EventVisibility;
use Konventix\Event\Enums\ReentryPolicy;
use Konventix\Event\Models\Event;
use Spatie\LaravelData\Attributes\Validation\Date;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Nullable;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Attributes\Validation\StringType;
use Spatie\LaravelData\Attributes\Validation\Timezone;
use Spatie\LaravelData\Attributes\Validation\Ulid;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Single source of validation rules, Inertia props shape, and generated
 * TypeScript for the event form (ADR-0009).
 *
 * `startsAt`/`endsAt` are wall-clock strings (`Y-m-d H:i:s`), not instants —
 * ADR-0027. They are stored on the model verbatim; the model's
 * `startsAtInstant()`/`endsAtInstant()` accessors do the timezone conversion.
 */
#[TypeScript]
final class EventData extends Data
{
    public function __construct(
        #[Required, StringType, Max(255)]
        public readonly string $name,

        #[Required, StringType, Max(255), Regex('/^[a-z0-9]+(-[a-z0-9]+)*$/')]
        public readonly string $slug,

        #[Nullable, StringType, Max(500)]
        public readonly ?string $description,

        #[Required, Date]
        public readonly string $startsAt,

        #[Required, Date]
        public readonly string $endsAt,

        #[Required, Timezone]
        public readonly string $timezone,

        #[Required]
        public readonly EventVisibility $visibility,

        #[Required]
        public readonly ReentryPolicy $reentryPolicy,

        #[Nullable, Ulid]
        public readonly ?string $predecessorEventId = null,
        /** A media-library ULID (ADR-0035), owned by the event's brand; null for no banner (#292). */
        #[Nullable, Ulid]
        public readonly ?string $bannerMediaId = null,
    ) {}

    /**
     * @return array<string, array<int, string>>
     */
    public static function rules(): array
    {
        return [
            // Cross-field, so it cannot live on the `endsAt` attribute alone.
            'endsAt' => ['after:startsAt'],
        ];
    }

    /**
     * Explicit projection from the model rather than relying on automatic
     * reflection-based normalization: `starts_at`/`ends_at` are Eloquent
     * `datetime` casts (Carbon instances), and this keeps their wall-clock
     * string representation unambiguous instead of round-tripping through a
     * Carbon-to-string cast (ADR-0027).
     */
    public static function fromModel(Event $event): self
    {
        return new self(
            name: $event->name,
            slug: $event->slug,
            description: $event->description,
            startsAt: $event->starts_at->format('Y-m-d H:i:s'),
            endsAt: $event->ends_at->format('Y-m-d H:i:s'),
            timezone: $event->timezone,
            visibility: $event->visibility,
            reentryPolicy: $event->reentry_policy,
            predecessorEventId: $event->predecessor_event_id,
            bannerMediaId: $event->banner_media_id,
        );
    }
}
