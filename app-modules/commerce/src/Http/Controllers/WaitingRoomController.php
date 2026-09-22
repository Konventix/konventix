<?php

declare(strict_types=1);

namespace Konventix\Commerce\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Konventix\Commerce\Data\WaitingRoomStatusData;
use Konventix\Commerce\Models\WaitingRoomSettings;
use Konventix\Commerce\WaitingRoom\TicketCookie;
use Konventix\Commerce\WaitingRoom\TicketHandoff;
use Konventix\Commerce\WaitingRoom\WaitingRoomStore;
use Konventix\Platform\Brands\BrandContext;

/**
 * The holding page (ADR-0047 point 5, Task 13) `CommerceWaitingRoomGate`
 * sends a not-yet-admitted visitor to, and the JSON endpoint its poll
 * reads. Route middleware is deliberately narrow (`routes/web/commerce.php`):
 * `ensure-feature:commerce:waiting-room` so a brand that never switched the
 * feature on 404s here exactly like `/shop` does, but **not**
 * `ensure-waiting-room` — this page IS the waiting room, so gating it
 * behind itself would loop the visitor straight back here — and **not**
 * `auth`, since a guest queues exactly like a signed-in visitor.
 *
 * `BrandContext` is a method parameter, not constructor-injected
 * (.ai/rules/providers.md): it is a scoped binding, and this controller,
 * bound fresh per request by the container, must resolve it at call time
 * rather than capture it once.
 */
final class WaitingRoomController
{
    public function __construct(private readonly WaitingRoomStore $store) {}

    /**
     * A visitor with no ticket at all — neither cookie nor handoff — has
     * nothing to show here: they are sent to `/shop`, which the gate will
     * queue them from afresh rather than this page minting a ticket of its
     * own. One already holding an admitted, unexpired ticket (a bookmark,
     * the back button) is sent straight on to where it was heading;
     * showing them a queue position they no longer have would be a lie.
     */
    public function show(Request $request, BrandContext $brandContext): Response|RedirectResponse
    {
        $brand = $brandContext->current();
        abort_if($brand === null, 404);

        $brandId = $brand->getKey();
        $ticketId = $this->ticketId($request, $brandId);

        if ($ticketId === null) {
            return redirect()->route('commerce.shop.index');
        }

        if ($this->store->isAdmitted($brandId, $ticketId)) {
            return redirect()->to($this->store->intendedUrl($brandId, $ticketId) ?? route('commerce.shop.index'));
        }

        $position = $this->store->positionOf($brandId, $ticketId);

        // The store can forget a ticket before it is ever admitted (its 2h
        // TTL) — a cookie the store no longer recognizes has nothing to
        // report a position for, so send the visitor back through the gate
        // to re-queue rather than rendering a lie ("number 0").
        if ($position === null) {
            return redirect()->route('commerce.shop.index');
        }

        $settings = WaitingRoomSettings::forBrand($brandId);

        return Inertia::render('waiting-room/Show', [
            'position' => $position,
            'depth' => $this->store->depth($brandId),
            'admitPerInterval' => $settings->admit_per_interval,
            'intervalSeconds' => 5,
        ]);
    }

    /**
     * What the page's poll reads every five seconds. `redirectTo` is the
     * ticket's own intended destination once admitted (falling back to
     * `/shop` for the rare ticket whose stored URL has expired out of the
     * store already), and null while still queued — the page only ever
     * navigates away once this says to.
     */
    public function status(Request $request, BrandContext $brandContext): JsonResponse
    {
        $brand = $brandContext->current();
        abort_if($brand === null, 404);

        $brandId = $brand->getKey();
        $ticketId = $this->ticketId($request, $brandId);

        return response()->json(WaitingRoomStatusData::ofStore($this->store, $brandId, $ticketId));
    }

    /**
     * The cookie first, the gate's handoff token second (#221). A client
     * that kept the cookie never reaches the second branch, so nothing
     * about an ordinary browser's visit changes; one that could not keep
     * it is answered from the token the gate put in the redirect instead
     * of being bounced back to `/shop` to mint another ticket. Both are
     * HMAC-signed over `APP_KEY` in their own domain — a forged or
     * tampered value reads as "no ticket", never as someone else's place
     * in the queue ({@see TicketHandoff} for why the token is the weaker
     * of the two on purpose).
     */
    private function ticketId(Request $request, string $brandId): ?string
    {
        $raw = $request->cookie(TicketCookie::name($brandId));
        $fromCookie = TicketCookie::parse(is_string($raw) ? $raw : null);

        if ($fromCookie !== null) {
            return $fromCookie;
        }

        $handoff = $request->query(TicketHandoff::QUERY_PARAMETER);

        return TicketHandoff::parse(is_string($handoff) ? $handoff : null);
    }
}
