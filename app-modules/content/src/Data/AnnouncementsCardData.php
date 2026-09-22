<?php

declare(strict_types=1);

namespace Konventix\Content\Data;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The live hub's announcements card (D-HUB-05, design §5.2): the last
 * three things the organizers said about this event, and the way to the
 * rest of them.
 *
 * A teaser of `/news`, never a second feed — `$newsUrl` is in the payload
 * rather than built in the component so the card keeps naming the route
 * Content owns even if that route moves.
 */
#[TypeScript]
final class AnnouncementsCardData extends Data
{
    /**
     * @param  array<int, AnnouncementItemData>  $entries
     */
    public function __construct(
        #[DataCollectionOf(AnnouncementItemData::class)]
        public readonly array $entries,
        public readonly string $newsUrl,
    ) {}
}
