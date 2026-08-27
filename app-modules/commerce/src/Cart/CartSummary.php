<?php

declare(strict_types=1);

namespace Konventix\Commerce\Cart;

use Illuminate\Http\Request;
use Konventix\Commerce\Models\Cart;
use Konventix\Platform\Brands\BrandContext;
use Laravel\Pennant\Feature;

/**
 * The shared `cart.count` Inertia prop's own doorway (ADR-0047, Task 11):
 * `HandleInertiaRequests` calls `countFor()` on every request rather than
 * knowing what a cart is — the host layer may not import
 * `Konventix\Commerce\*` domain data, only resolve a service by name
 * (`tests/Unit/ArchitectureTest.php`'s dependency check only flags a real
 * type-hinted dependency — extends/implements/param/return/property types —
 * never a plain `use` import resolved through `app()`, which is exactly
 * the pattern `HandleInertiaRequests` already uses for Platform's own
 * registries).
 *
 * Zero for a guest, off the brand host entirely, or on a brand that never
 * switched `commerce:shop` on — the same three conditions the route
 * middleware itself gates the cart behind, so the badge never counts what
 * the topbar does not also link to.
 */
final class CartSummary
{
    public function __construct(private readonly BrandContext $brandContext) {}

    public function countFor(Request $request): int
    {
        $brand = $this->brandContext->current();

        if ($brand === null) {
            return 0;
        }

        $user = $request->user();

        if ($user === null) {
            return 0;
        }

        if (! Feature::for($brand)->active('commerce:shop')) {
            return 0;
        }

        $cart = Cart::query()
            ->open()
            ->where('payer_user_id', (string) $user->getAuthIdentifier())
            ->first();

        return $cart?->lines()->count() ?? 0;
    }
}
