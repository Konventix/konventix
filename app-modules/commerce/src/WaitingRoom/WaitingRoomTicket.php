<?php

declare(strict_types=1);

namespace Konventix\Commerce\WaitingRoom;

/**
 * What `IssueWaitingRoomTicket` hands back — freshly minted, or re-read
 * from an existing cookie/user mapping the store still recognizes.
 */
final readonly class WaitingRoomTicket
{
    public function __construct(
        public string $id,
        /** Null once the ticket has been admitted (popped from the queue). */
        public ?int $position,
        public bool $isAdmitted,
    ) {}
}
