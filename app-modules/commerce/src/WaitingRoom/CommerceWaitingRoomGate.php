<?php

declare(strict_types=1);

namespace Konventix\Commerce\WaitingRoom;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Konventix\Commerce\Enums\Permission;
use Konventix\Platform\Brands\BrandContext;
use Konventix\Platform\WaitingRoom\WaitingRoomGate;
use Laravel\Pennant\Feature;
use Symfony\Component\HttpFoundation\Response;

/**
 * Commerce's implementation of Platform's contract (ADR-0047 point 5),
 * bound in `CommerceServiceProvider::registerBindings()`. Checked in order,
 * each one that matches passing the request through with no further work:
 *
 * 1. No brand resolved, or the brand never switched `commerce:waiting-room`
 *    on — nothing to gate.
 * 2. A signed-in user holding `commerce.manage-products` on this brand — an
 *    operator working the shop must never queue behind their own rush.
 * 3. An admitted, unexpired ticket — see {@see IssueWaitingRoomTicket}.
 *
 * Otherwise a ticket is issued or re-read, its cookie is set on the
 * redirect, and the visitor is sent to the holding page — with the ticket
 * repeated in the redirect's query string ({@see TicketHandoff}). The
 * cookie is still the credential; the query parameter only lets the
 * holding page answer a client that could not keep it, instead of bouncing
 * it back here for a second ticket. That bounce was the loop of #221: a
 * cookie-less `curl -L /shop` spent all five of the hour's per-IP tickets
 * in one walk.
 *
 * `BrandContext` is resolved at call time, not constructor-injected — this
 * class is bound with `bind()` (a fresh instance per resolution), so
 * either would be Octane-safe, but call-time resolution matches the
 * pattern the rest of this codebase uses for a scoped dependency
 * (`.ai/rules/providers.md`).
 */
final class CommerceWaitingRoomGate implements WaitingRoomGate
{
    public function __construct(private readonly IssueWaitingRoomTicket $issueTicket) {}

    public function guard(Request $request): ?Response
    {
        $brand = app(BrandContext::class)->current();

        if ($brand === null || ! Feature::for($brand)->active('commerce:waiting-room')) {
            return null;
        }

        $user = $request->user();

        if ($user !== null && Gate::forUser($user)->allows(Permission::ManageProducts->value)) {
            return null;
        }

        $ticket = $this->issueTicket->handle($brand, $request);

        if ($ticket->isAdmitted) {
            return null;
        }

        $response = redirect()->route('commerce.waiting-room.show', [
            TicketHandoff::QUERY_PARAMETER => TicketHandoff::issue($ticket->id),
        ]);

        $response->headers->setCookie(cookie(
            name: TicketCookie::name($brand->getKey()),
            value: TicketCookie::issue($ticket->id),
            minutes: 30,
            path: '/',
            secure: $request->secure(),
            httpOnly: true,
            sameSite: 'lax',
        ));

        return $response;
    }
}
