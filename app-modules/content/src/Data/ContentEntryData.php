<?php

declare(strict_types=1);

namespace Konventix\Content\Data;

use Konventix\Content\Enums\Audience;
use Konventix\Content\Enums\ContentType;
use Konventix\Content\Enums\Urgency;
use Konventix\Content\Models\ContentEntry;
use Spatie\LaravelData\Attributes\Validation\BooleanType;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Nullable;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Attributes\Validation\StringType;
use Spatie\LaravelData\Attributes\Validation\Ulid;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Single source of validation rules, Inertia props shape, and generated
 * TypeScript for the entry editor (ADR-0009).
 *
 * Deliberately carries no `state` or `publishedAt`: publication is a
 * lifecycle Action with events attached (`PublishEntry`, `UnpublishEntry`),
 * not a field an editor can type over. The editor form saves *content*; the
 * lifecycle buttons move the entry.
 *
 * `urgency` is announcement-only (D-CNT-08). The form may send it for any
 * type; `CreateEntry`/`UpdateEntry` drop it on everything else rather than
 * storing an intent nobody will read.
 */
#[TypeScript]
final class ContentEntryData extends Data
{
    public function __construct(
        #[Required]
        public readonly ContentType $type,

        #[Required, StringType, Max(255)]
        public readonly string $title,

        #[Required, StringType, Max(255), Regex('/^[a-z0-9]+(-[a-z0-9]+)*$/')]
        public readonly string $slug,

        #[Required]
        public readonly Audience $audience,

        #[Required, BooleanType]
        public readonly bool $commentsEnabled,

        #[Nullable, StringType, Max(1000)]
        public readonly ?string $teaser = null,

        #[Nullable, StringType]
        public readonly ?string $body = null,

        #[Nullable]
        public readonly ?Urgency $urgency = null,

        #[Nullable, Ulid]
        public readonly ?string $eventId = null,
    ) {}

    public static function fromModel(ContentEntry $entry): self
    {
        return new self(
            type: $entry->type,
            title: $entry->title,
            slug: $entry->slug,
            audience: $entry->audience,
            commentsEnabled: $entry->comments_enabled,
            teaser: $entry->teaser,
            body: $entry->body,
            urgency: $entry->urgency,
            eventId: $entry->event_id,
        );
    }
}
