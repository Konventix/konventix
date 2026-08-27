<?php

declare(strict_types=1);

namespace Konventix\Commerce\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Konventix\Commerce\Models\WaitingRoomSettings;
use Konventix\Commerce\WaitingRoom\TicketCookie;
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
     * A visitor with no valid ticket cookie has nothing to show here —
     * they are sent to `/shop`, which the gate will queue them from
     * afresh rather than this page minting a ticket of its own. One
     * already holding an admitted, unexpired ticket (a bookmark, the back
     * button) is sent straight on to where it was heading; showing them a
     * queue position they no longer have would be a lie.
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

        $settings = WaitingRoomSettings::forBrand($brandId);

        return Inertia::render('waiting-room/Show', [
            'position' => $this->store->positionOf($brandId, $ticketId) ?? 0,
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
        $depth = $this->store->depth($brandId);

        if ($ticketId === null) {
            return response()->json([
                'position' => null,
                'admitted' => false,
                'redirectTo' => null,
                'depth' => $depth,
            ]);
        }

        $admitted = $this->store->isAdmitted($brandId, $ticketId);

        return response()->json([
            'position' => $this->store->positionOf($brandId, $ticketId),
            'admitted' => $admitted,
            'redirectTo' => $admitted
                ? ($this->store->intendedUrl($brandId, $ticketId) ?? route('commerce.shop.index'))
                : null,
            'depth' => $depth,
        ]);
    }

    private function ticketId(Request $request, string $brandId): ?string
    {
        $raw = $request->cookie(TicketCookie::name($brandId));

        return TicketCookie::parse(is_string($raw) ? $raw : null);
    }
}
