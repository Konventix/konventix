<?php

declare(strict_types=1);

namespace Konventix\Commerce\Http\Controllers;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Konventix\Commerce\Actions\AddProductToCart;
use Konventix\Commerce\Actions\ChangeCartLineQuantity;
use Konventix\Commerce\Actions\OpenCart;
use Konventix\Commerce\Actions\PlaceOrderFromCart;
use Konventix\Commerce\Actions\RemoveCartLine;
use Konventix\Commerce\Data\AddToCartData;
use Konventix\Commerce\Data\CartData;
use Konventix\Commerce\Data\CartLineData;
use Konventix\Commerce\Data\CartLineQuantityData;
use Konventix\Commerce\Data\CheckoutAccountData;
use Konventix\Commerce\Enums\CartLineKind;
use Konventix\Commerce\Enums\CartStatus;
use Konventix\Commerce\Exceptions\StockExhaustedException;
use Konventix\Commerce\Models\Cart;
use Konventix\Commerce\Models\CartLine;
use Konventix\Commerce\Models\ProductVariant;
use Konventix\Commerce\Models\SettlementAccount;
use Konventix\Commerce\Settlement\ProvidesHostedCheckout;
use Konventix\Commerce\Settlement\SettlementAdapterRegistry;
use Konventix\Commerce\Tax\TaxCalculator;
use Konventix\Platform\Brands\BrandContext;
use Konventix\Platform\Operators\OperatorSettings;
use LogicException;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * The person-owned cart page and its lines (ADR-0047, Task 11), on the
 * brand's own domain behind `ensure-feature:commerce:shop` + `auth`
 * (`routes/web/commerce.php`) — a guest never reaches any of this, and a
 * brand that never switched shopping on 404s before any action runs.
 *
 * Brand scoping is `BelongsToBrand`'s global scope throughout: this
 * controller only ever runs on a brand host, unlike the operator console.
 */
final class CartController
{
    public function __construct(
        private readonly TaxCalculator $taxCalculator,
        private readonly OperatorSettings $operatorSettings,
        private readonly SettlementAdapterRegistry $registry,
    ) {}

    public function show(Request $request, BrandContext $brandContext): Response
    {
        $brand = $brandContext->current();
        abort_if($brand === null, 404);

        $cart = $this->openCartFor($request);
        $lines = $cart?->lines()->get() ?? new Collection;

        $calculation = $lines->isEmpty() ? null : $this->taxCalculator->calculate(
            lines: array_values($lines->map(fn (CartLine $line): array => [
                'label' => $line->label,
                'quantity' => $line->quantity,
                'unit_amount_net' => $line->unit_amount_net,
                'tax_class' => $line->tax_class->value,
            ])->all()),
            operatorSmallBusinessScheme: $this->operatorSettings->sellerForBrand($brand->id)->smallBusinessScheme,
            billingVatId: null,
            billingCountryCode: null,
        );
        $totalNet = $calculation === null ? '0.00' : $calculation->totalNet;
        $totalTax = $calculation === null ? '0.00' : $calculation->totalTax;
        $totalGross = $calculation === null ? '0.00' : $calculation->totalGross;

        $variantsById = ProductVariant::query()
            ->whereIn('id', $lines->where('kind', CartLineKind::Product)->pluck('ref_id'))
            ->get()
            ->keyBy('id');

        $lineData = array_values($lines->values()->map(function (CartLine $line, int $index) use ($calculation, $variantsById): CartLineData {
            $calculatedLine = $calculation === null ? null : $calculation->lines[$index];
            $variant = $line->kind === CartLineKind::Product ? $variantsById->get($line->ref_id) : null;

            return CartLineData::ofLine(
                $line,
                $calculatedLine !== null ? $calculatedLine->lineGross : $line->unit_amount_net,
                $variant === null ? null : $variant->remaining() + $line->quantity,
            );
        })->all());

        return Inertia::render('cart/Show', [
            'cart' => CartData::ofCart(
                $cart,
                $lineData,
                $totalNet,
                $totalTax,
                $totalGross,
                $this->operatorSettings->currencyForBrand($brand->id),
                array_values($this->offeredAccounts($brand->id)->map(fn (SettlementAccount $account): CheckoutAccountData => CheckoutAccountData::ofAccount(
                    $account,
                    $this->registry->forProvider($account->provider),
                ))->all()),
            )->toArray(),
        ]);
    }

    public function storeLine(AddToCartData $data, Request $request, BrandContext $brandContext): RedirectResponse
    {
        $brand = $brandContext->current();
        abort_if($brand === null, 404);

        $variant = ProductVariant::query()->whereKey($data->variantId)->firstOrFail();
        $cart = OpenCart::for($brand->id, (string) $request->user()->getAuthIdentifier());

        try {
            app(AddProductToCart::class)->handle($cart, $variant, $data->quantity);
        } catch (StockExhaustedException) {
            throw ValidationException::withMessages([
                'quantity' => __('Not enough stock remains.'),
            ]);
        }

        return back();
    }

    public function updateLine(CartLineQuantityData $data, Request $request, string $line): RedirectResponse
    {
        $cartLine = $this->cartLineFor($request, $line);

        try {
            app(ChangeCartLineQuantity::class)->handle($cartLine, $data->quantity);
        } catch (StockExhaustedException) {
            throw ValidationException::withMessages([
                'quantity' => __('Not enough stock remains.'),
            ]);
        } catch (LogicException $e) {
            abort(422, $e->getMessage());
        }

        return back();
    }

    public function destroyLine(Request $request, string $line): RedirectResponse
    {
        app(RemoveCartLine::class)->handle($this->cartLineFor($request, $line));

        return back();
    }

    public function checkout(Request $request): SymfonyResponse
    {
        $cart = $this->cartForCheckout($request);

        $validated = $request->validate([
            'account' => ['required', 'ulid'],
        ]);

        $account = $this->offeredAccounts($cart->brand_id)->firstWhere('id', $validated['account']);

        // An id that is unknown, not offered, or another brand's all land
        // here alike: refused with a validation error on `account`, the
        // same as `CheckoutController::start()`.
        if ($account === null) {
            throw ValidationException::withMessages([
                'account' => __('Choose one of the offered payment options.'),
            ]);
        }

        try {
            $redirect = app(PlaceOrderFromCart::class)->handle($cart, $account, route('commerce.cart.show'));
        } catch (RuntimeException $e) {
            abort(409, $e->getMessage());
        }

        // A hard redirect out of the SPA to the provider's hosted page.
        return Inertia::location($redirect->url);
    }

    /**
     * The payer's own open cart, or null — the cart page's empty state
     * when there is none. Never a bare `Cart::find()`: another payer's
     * cart, on this same brand, must never resolve here.
     */
    private function openCartFor(Request $request): ?Cart
    {
        return Cart::query()
            ->open()
            ->where('payer_user_id', (string) $request->user()->getAuthIdentifier())
            ->first();
    }

    /**
     * Checkout also accepts a cart already `checked_out` — a double-submit
     * or a retried request must still find the cart `PlaceOrderFromCart`'s
     * own idempotent branch resumes, not 404 the second attempt.
     */
    private function cartForCheckout(Request $request): Cart
    {
        return Cart::query()
            ->whereIn('status', [CartStatus::Open, CartStatus::CheckedOut])
            ->where('payer_user_id', (string) $request->user()->getAuthIdentifier())
            ->latest('updated_at')
            ->firstOrFail();
    }

    /**
     * The `{line}` param resolved through the payer's own open cart —
     * never a bare `CartLine::findOrFail()`, which would let another
     * payer's line resolve on this brand.
     */
    private function cartLineFor(Request $request, string $line): CartLine
    {
        $cart = Cart::query()
            ->open()
            ->where('payer_user_id', (string) $request->user()->getAuthIdentifier())
            ->firstOrFail();

        return $cart->lines()->findOrFail($line);
    }

    /**
     * Every account the payer may choose from (ADR-0046), same as
     * `CheckoutController::offeredAccounts()`.
     *
     * @return Collection<int, SettlementAccount>
     */
    private function offeredAccounts(string $brandId): Collection
    {
        return SettlementAccount::liveAccountsFor($brandId)
            ->filter(fn (SettlementAccount $account): bool => $account->isOffered()
                && $this->registry->forProvider($account->provider) instanceof ProvidesHostedCheckout)
            ->values();
    }
}
