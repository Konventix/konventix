<?php

declare(strict_types=1);

namespace Konventix\Content\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One line of the live hub's announcements card (D-HUB-05, design §5.2):
 * enough to decide whether to open it, and the way to.
 *
 * `#[TypeScript]` because the card's Vue component types its props from
 * this shape — `HubCardData::$payload` is `Record<string, unknown>` on the
 * wire, so the generated interface is the only typed contract between the
 * two sides.
 *
 * `publishedAt` is an instant as ISO-8601 (`.ai/rules/js.md`); the card
 * prints it through the shared formatter, in the reader's own zone.
 */
#[TypeScript]
final class AnnouncementItemData extends Data
{
    public function __construct(
        public readonly string $title,
        public readonly ?string $teaser,
        public readonly string $url,
        public readonly string $publishedAt,
    ) {}
}
