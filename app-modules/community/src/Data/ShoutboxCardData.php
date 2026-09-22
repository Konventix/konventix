<?php

declare(strict_types=1);

namespace Konventix\Community\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The hub card `community.shoutbox` (design §8): the last few lines and an
 * inline compose box.
 *
 * The three URLs travel in the payload because the card may not import a
 * route helper — `tests/Feature/HubComponentBoundaryTest.php` keeps a hub
 * component to the shared frontend and its own module, and the payload is
 * the one channel the hub shell hands it (`MyTicketCard`'s idiom).
 */
#[TypeScript]
final class ShoutboxCardData extends Data
{
    public function __construct(
        public readonly ShoutboxWallData $wall,
        public readonly string $roomUrl,
        public readonly string $messagesUrl,
        public readonly string $postUrl,
    ) {}
}
