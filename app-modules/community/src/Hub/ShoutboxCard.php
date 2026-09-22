<?php

declare(strict_types=1);

namespace Konventix\Community\Hub;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Konventix\Community\Data\ShoutboxCardData;
use Konventix\Community\Rooms\EventRooms;
use Konventix\Community\Rooms\WallReader;
use Konventix\Platform\Data\ActiveEventData;

/**
 * The payload behind the hub's `community.shoutbox` card (design §8): the
 * last few lines of the running event's wall and an inline compose box.
 *
 * An invokable builder resolved per request by the closure in
 * `CommunityServiceProvider::registerHub()` — `NowNextCard`'s shape — so the
 * singleton registry holds nothing of a request (`.ai/rules/providers.md`).
 *
 * The hub only renders for a member admitted to `$event`, so the room is
 * this event's and `RoomChannel::join()` lets them in; the room is created
 * here on the very first read if nobody has opened the wall yet (spec
 * §4.1). A refusal is therefore not a state this card has an answer for,
 * and it throws: `HubPage` contains a throwing card, drops it and counts
 * it, which is the right outcome for "the access rules disagree with each
 * other".
 */
final class ShoutboxCard
{
    public function __construct(
        private readonly EventRooms $rooms,
        private readonly WallReader $wall,
    ) {}

    public function __invoke(ActiveEventData $event, Authenticatable $user): ShoutboxCardData
    {
        $room = $this->rooms->authorised($event->eventId, $user)
            ?? throw new AuthorizationException('The shoutbox refused a member the hub admitted.');

        return new ShoutboxCardData(
            wall: $this->wall->wall($room, (string) $user->getAuthIdentifier(), WallReader::CARD_LINES),
            roomUrl: route('community.shoutbox.show', absolute: false),
            messagesUrl: route('community.shoutbox.messages', absolute: false),
            postUrl: route('community.shoutbox.messages.store', absolute: false),
        );
    }
}
