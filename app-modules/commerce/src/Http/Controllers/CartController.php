<?php

declare(strict_types=1);

namespace Konventix\Commerce\Http\Controllers;

use Illuminate\Database\Eloquent\Builder;
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
use Konventix\Commerce\Cart\ProductPageUrls;
use Konventix\Commerce\Data\AddToCartData;
use Konventix\Commerce\Data\BillingPartyData;
use Konventix\Commerce\Data\CartData;
use Konventix\Commerce\Data\CartLineData;
use Konventix\Commerce\Data\CartLineQuantityData;
use Konventix\Commerce\Data\CheckoutAccountData;
use Konventix\Commerce\Enums\CartLineKind;
use Konventix\Commerce\Enums\CartStatus;
use Konventix\Commerce\Events\CheckoutConsentGiven;
use Konventix\Commerce\Exceptions\CartCheckoutRefusedException;
use Konventix\Commerce\Exceptions\StockExhaustedException;
use Konventix\Commerce\Models\Cart;
use Konventix\Commerce\Models\CartLine;
use Konventix\Commerce\Models\Order;
use Konventix\Commerce\Models\Product;
use Konventix\Commerce\Models\ProductVariant;
use Konventix\Commerce\Models\SettlementAccount;
use Konventix\Commerce\Settlement\CheckoutConsentSlots;
use Konventix\Commerce\Settlement\ProvidesHostedCheckout;
use Konventix\Commerce\Settlement\SettlementAdapterRegistry;
use Konventix\Commerce\Tax\GermanVat;
use Konventix\Commerce\Tax\TaxCalculator;
use Konventix\Platform\Brands\BrandContext;
use Konventix\Platform\Operators\OperatorSettings;
use LogicException;
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
        private readonly CheckoutConsentSlots $consentSlots,
    ) {}

    public function show(Request $request, BrandContext $brandContext): Response|RedirectResponse
    {
        $brand = $brandContext->current();
        abort_if($brand === null, 404);

        // GitLab #205, review 6 finding 5: a republished contract text is
        // settled before the form is shown, not after the payer has picked
        // an account and ticked the box. Loop-free — `policies.required`
        // carries no gate of its own — and the intent it remembers brings
        // them straight back here.
        $consentSlots = $this->consentSlots->contributions();
        $outstandingUrl = $this->consentSlots->outstandingUrl($consentSlots);

        if ($outstandingUrl !== null) {
            $request->session()->put('url.intended', route('commerce.cart.show', absolute: false));

            return redirect()->to($outstandingUrl);
        }

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
        $productUrls = app(ProductPageUrls::class)->forVariantIds($variantsById->keys());

        $lineData = array_values($lines->values()->map(function (CartLine $line, int $index) use ($calculation, $variantsById, $productUrls): CartLineData {
            $calculatedLine = $calculation === null ? null : $calculation->lines[$index];
            $variant = $line->kind === CartLineKind::Product ? $variantsById->get($line->ref_id) : null;

            return CartLineData::ofLine(
                $line,
                $calculatedLine !== null ? $calculatedLine->lineGross : $line->unit_amount_net,
                $variant === null ? null : $variant->remaining() + $line->quantity,
                url: match ($line->kind) {
                    CartLineKind::Product => $productUrls[$line->ref_id] ?? null,
                    CartLineKind::Registration => route('registration.mine.show', ['registration' => $line->ref_id], absolute: false),
                },
            );
        })->all());

        return Inertia::render('cart/Show', [
            // Compliance's consent control (GitLab #2). Commerce may not read
            // a policy row, so the module that owns the legal texts computes
            // its own props here through Platform's slot registry (ADR-0003
            // enforcement point 3), exactly as Registration contributes to
            // Event's landing page.
            'consentSlots' => $consentSlots,
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
                // §33 UStDV vs §14 Abs. 4 UStG (#4): the same figure
                // `PlaceOrderFromCart::guardBillingAddress()` checks at
                // submit time, shown here so the page can ask for the
                // address up front rather than only after a 422.
                GermanVat::requiresBillingAddress($totalGross),
            )->toArray(),
        ]);
    }

    public function storeLine(AddToCartData $data, Request $request, BrandContext $brandContext): RedirectResponse
    {
        $brand = $brandContext->current();
        abort_if($brand === null, 404);

        // Published only — an operator's draft product must not be
        // addable to a cart just because its variant id leaked somehow;
        // the shop itself never links to one.
        $variant = ProductVariant::query()
            ->whereKey($data->variantId)
            ->whereHas('product', function (Builder $query): Builder {
                /** @var Builder<Product> $query */
                return $query->published();
            })
            ->firstOrFail();
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
        } catch (LogicException) {
            throw ValidationException::withMessages([
                'quantity' => __("This line's quantity is fixed."),
            ]);
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
        $consentSlots = $this->consentSlots->contributions();

        // GitLab #205: the page gate above is the one a payer normally
        // meets; this is the one that holds when the version was published
        // between the render and the submit. Checked before validation so
        // the payer is not first told to tick a box on a page they are
        // about to be sent away from. The intent is the cart page,
        // deliberately, not this POST: agreeing has to land them back where
        // they were, and a consent screen may not replay a purchase.
        $outstandingUrl = $this->consentSlots->outstandingUrl($consentSlots);

        if ($outstandingUrl !== null) {
            $request->session()->put('url.intended', route('commerce.cart.show', absolute: false));

            return redirect()->to($outstandingUrl);
        }

        // "Zahlungspflichtig bestellen" concludes the contract, so the
        // declaration is a field of this request, not a courtesy on the page
        // (GitLab #2). `accepted` is what puts the error on `accept_terms`
        // for the control that raised it (ADR-0055). Demanded only where
        // there is something to declare: an instance with no published texts
        // renders no control, and a checkout that insisted on one anyway
        // could never be completed.
        //
        // Billing address fields stay nullable here regardless of the
        // order's total (#4): whether they are actually required depends on
        // the cart's *computed* gross, which is not known until
        // `PlaceOrderFromCart` runs the same tax calculation this
        // controller cannot repeat without risking the two ever disagreeing
        // — `PlaceOrderFromCart::guardBillingAddress()` is the one place
        // that refuses a submission missing them. Deliberately not a Data
        // object for this whole form (unlike `storeLine()`/`AddToCartData`):
        // injecting one validates before the method body runs, the same as
        // a `FormRequest`, which would run it *before* the outstandingUrl
        // gate above — exactly the ordering GitLab #205 fixed.
        $validated = $request->validate(array_merge(
            $this->consentSlots->declarationRequired($consentSlots)
                ? ['account' => ['required', 'ulid'], 'accept_terms' => ['accepted']]
                : ['account' => ['required', 'ulid']],
            [
                'billingAddressLine' => ['nullable', 'string', 'max:255'],
                'billingPostalCode' => ['nullable', 'string', 'max:16'],
                'billingCity' => ['nullable', 'string', 'max:255'],
                'billingCountryCode' => ['nullable', 'string', 'size:2'],
            ],
        ));

        $cart = $this->cartForCheckout($request);

        $account = $this->offeredAccounts($cart->brand_id)->firstWhere('id', $validated['account']);

        // An id that is unknown, not offered, or another brand's all land
        // here alike: refused with a validation error on `account`, the
        // same as `CheckoutController::start()`.
        if ($account === null) {
            throw ValidationException::withMessages([
                'account' => __('Choose one of the offered payment options.'),
            ]);
        }

        // ADR-0048: a cart order needs a billing party to invoice against
        // once it is paid. Name and email always come from the account; the
        // address fields above ride along only when the payer filled them
        // in — required or not is `PlaceOrderFromCart`'s own call, once it
        // knows the cart's actual computed total (#4,
        // `BillingPartyData::ofAccount`'s own docblock).
        $billingParty = BillingPartyData::ofAccount(
            (string) $request->user()->name,
            (string) $request->user()->email,
            $validated['billingAddressLine'] ?? null,
            $validated['billingPostalCode'] ?? null,
            $validated['billingCity'] ?? null,
            $validated['billingCountryCode'] ?? null,
        );

        // Recorded before the order is placed: the declaration is what
        // authorizes it, and an adapter failure a moment later must not lose
        // the record that it was made. Compliance decides which documents
        // and which version — Commerce never writes a consent row (ADR-0004).
        if ($this->consentSlots->declarationRequired($consentSlots)) {
            event(new CheckoutConsentGiven(
                brandId: $cart->brand_id,
                subjectId: (string) $request->user()->getAuthIdentifier(),
                captureContext: 'checkout',
                acceptedAt: now()->toIso8601String(),
            ));
        }

        // Only the Action's own guards (cart state, account offered) are
        // caught here — a real adapter failure past that point must reach
        // the payer the way `CheckoutController::start()` lets it, not
        // disguised as a 409 domain refusal.
        //
        // The provider sends the payer back to the order's own page
        // (`commerce.mine.show`, Task 3 of the 2026-09-21 bugfix round),
        // never to this cart: it is `checked_out` by then and the page
        // greeted a payer who had just paid with "Your cart is empty". A
        // closure, because the order does not exist yet — the Action
        // resolves it once it has placed or reused one. Absolute on
        // purpose: the URL leaves this host for the provider's session.
        try {
            $redirect = app(PlaceOrderFromCart::class)->handle(
                $cart,
                $account,
                fn (Order $order): string => route('commerce.mine.show', ['order' => $order->getKey()]),
                $billingParty,
            );
        } catch (CartCheckoutRefusedException $e) {
            abort(409, $e->getMessage());
        }

        // A hard redirect out of the SPA to the provider's hosted page.
        return Inertia::location($redirect->url);
    }

    /**
     * The payer's own open cart, or null — the cart page's empty state
     * when there is none. Never a bare `Cart::find()`: another payer's
     * cart, on this same brand, must never resolve here.
     *
     * Open only. A cart already `checked_out` is not shown here even while
     * its hold is alive (review 6 finding 1): this page's quantity and
     * remove controls resolve through `cartLineFor()`, which needs an open
     * cart, and rendering a state whose own controls 404 is worse than
     * rendering the empty state. Re-paying such an order is order-scoped
     * instead — `CheckoutController::showOrder()` and `/pay/order/{order}`
     * (#5).
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
