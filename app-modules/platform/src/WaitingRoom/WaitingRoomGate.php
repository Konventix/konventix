<?php

declare(strict_types=1);

namespace Konventix\Platform\WaitingRoom;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ADR-0047 point 5: the contract behind the `ensure-waiting-room` route
 * middleware alias. Platform owns the alias and this contract; Commerce
 * binds the implementation (`CommerceWaitingRoomGate`) because the queue,
 * the ticket and the per-brand settings are its own. A module standing
 * behind the door — Commerce's `/shop`/`/cart`, Registration's public form
 * — names only the alias string, never this interface or Commerce.
 *
 * `null` passes the request through; a non-null `Response` (the redirect to
 * the holding page, or a 429 for a capped IP) blocks it.
 */
interface WaitingRoomGate
{
    public function guard(Request $request): ?Response;
}
