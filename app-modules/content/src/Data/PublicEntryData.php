<?php

declare(strict_types=1);

namespace Konventix\Content\Data;

use Konventix\Content\Enums\ContentType;
use Konventix\Content\Enums\Urgency;
use Konventix\Content\Models\ContentEntry;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One entry as a reader sees it — listing row and detail page alike, since
 * the difference is only whether `body` is filled (ADR-0009: one `Data`
 * class as serialization and generated TS type at once).
 *
 * No `state` and no `author_user_id`: a reader only ever sees live entries,
 * and the byline is a display name resolved from Content's author read model
 * (ADR-0004), never an account id.
 */
#[TypeScript]
final class PublicEntryData extends Data
{
    public function __construct(
        public readonly string $slug,
        public readonly ContentType $type,
        public readonly string $title,
        public readonly ?string $teaser,
        public readonly ?string $body,
        public readonly ?Urgency $urgency,
        public readonly ?string $authorName,
        public readonly ?string $publishedAt,
        public readonly bool $commentsEnabled,
    ) {}

    /**
     * `$withBody` is explicit rather than inferred: a listing of forty
     * articles should not ship forty article bodies to the browser.
     */
    public static function ofModel(ContentEntry $entry, ?string $authorName = null, bool $withBody = true): self
    {
        return new self(
            slug: $entry->slug,
            type: $entry->type,
            title: $entry->title,
            teaser: $entry->teaser,
            body: $withBody ? $entry->body : null,
            urgency: $entry->urgency,
            authorName: $authorName,
            publishedAt: $entry->published_at?->toIso8601String(),
            commentsEnabled: $entry->comments_enabled,
        );
    }
}
