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
 * redirect, and the visitor is sent to the holding page.
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

        // TODO(tranche-3 T13): redirect to route('commerce.waiting-room.show')
        // once the holding page exists — a plain path for now.
        $response = redirect('/waiting-room');

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
