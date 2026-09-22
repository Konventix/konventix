<?php

declare(strict_types=1);

namespace Konventix\Platform\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The `/live` page's one prop (design §7.1): the event the member is
 * looking at, every event they could switch to, and the cards and links
 * already filtered and ordered by `HubPage` — the page adds nothing of its
 * own, so what it shows is exactly what the operator composed (design §6).
 */
#[TypeScript]
final class HubPageData extends Data
{
    /**
     * @param  array<int, ActiveEventData>  $events
     * @param  array<int, HubCardData>  $cards
     * @param  array<int, HubLinkData>  $links
     */
    public function __construct(
        public readonly ActiveEventData $event,
        public readonly array $events,
        public readonly array $cards,
        public readonly array $links,
    ) {}
}
