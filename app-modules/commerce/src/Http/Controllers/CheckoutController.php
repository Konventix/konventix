<?php

declare(strict_types=1);

namespace Konventix\Commerce\Http\Controllers;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Konventix\Commerce\Data\CheckoutAccountData;
use Konventix\Commerce\Enums\CartLineKind;
use Konventix\Commerce\Enums\OrderStatus;
use Konventix\Commerce\Enums\PaymentStatus;
use Konventix\Commerce\Enums\ReversalStatus;
use Konventix\Commerce\Events\CheckoutConsentGiven;
use Konventix\Commerce\Models\Cart;
use Konventix\Commerce\Models\CartLine;
use Konventix\Commerce\Models\Order;
use Konventix\Commerce\Models\OrderLine;
use Konventix\Commerce\Models\Payment;
use Konventix\Commerce\Models\PaymentReversal;
use Konventix\Commerce\Models\SettlementAccount;
use Konventix\Commerce\Settlement\CheckoutConsentSlots;
use Konventix\Commerce\Settlement\PaymentWindow;
use Konventix\Commerce\Settlement\ProvidesHostedCheckout;
use Konventix\Commerce\Settlement\SettlementAdapterRegistry;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Checkout for `instant` settlement (ADR-0039), addressed by the
 * registration the order settles — the one identifier the payer's flow
 * already carries, and the only coupling Registration needs (a ULID, never
 * a model, ADR-0006). Knowing the unguessable ULID authorizes viewing the
 * order summary, mirroring the waitlist-offer pattern; the page shows
 * lines and totals, never billing data.
 *
 * Behind `auth` since #2 — the page takes the payer's declaration on the
 * AGB and the Widerrufsbelehrung, and a Consent record needs a subject. See
 * the route file for why that is a change of kind, not just of gate.
 *
 * The page never decides payment truth: it *displays* whatever state the
 * webhook has written, including on return from the PSP — a payer can pay
 * and never return, or return before an async method settles.
 *
 * Since GitLab #5 the same controller also serves the *order*-addressed
 * pair, `showOrder()`/`startOrder()` on `/pay/order/{order}`: the way back
 * for a cart order (ADR-0047) whose payment failed. Order-scoped rather
 * than cart-scoped on purpose (review 6 findings 1, 2 and 4) — a payer who
 * kept shopping after an abandoned checkout has two carts, and a link that
 * named a cart would show, and check out, the wrong one. Naming the order
 * cannot be ambiguous, needs nothing from the cart page, and never touches
 * the reservation the first placement fixed.
 */
class CheckoutController
{
    public function __construct(
        private readonly SettlementAdapterRegistry $registry,
        private readonly CheckoutConsentSlots $consentSlots,
        private readonly PaymentWindow $paymentWindow,
    ) {}

    public function show(Request $request, string $registration): Response|RedirectResponse
    {
        $order = $this->orderFor($registration);

        // #210: with `commerce:shop` on, an `instant` settlement became a
        // cart line rather than an order (ADR-0047 point 3) and this page has
        // nothing to show. Handed on before the consent gate below: the cart
        // is where the payer is going, and `CartController` runs the same
        // gate there — gating a page we are leaving would only send them to
        // the policy and back to a dead end.
        if ($order === null) {
            return $this->cartHandoff($registration);
        }

        $consentSlots = $this->consentSlots->contributions();
        $redirect = $this->consentRedirect(
            $request,
            $consentSlots,
            route('commerce.pay.show', ['registration' => $registration], absolute: false),
        );

        if ($redirect !== null) {
            return $redirect;
        }

        $latestPayment = Payment::query()
            ->where('order_id', $order->id)
            ->latest('created_at')
            ->first();

        $hold = $this->holdFor($order);
        $refusal = $this->refusalReason($order, $hold);

        return Inertia::render('pay/Show', [
            'registrationId' => $registration,
            'order' => [
                'id' => $order->id,
                'status' => $order->status->value,
                'currency' => $order->currency,
                'totalNet' => $order->total_net,
                'totalTax' => $order->total_tax,
                'totalGross' => $order->total_gross,
                'lines' => $order->lines->map(fn (OrderLine $line): array => [
                    'label' => $line->label,
                    'quantity' => $line->quantity,
                    'lineGross' => $line->line_gross,
                ])->all(),
            ],
            'accounts' => $this->offeredAccounts($order)->map(fn (SettlementAccount $account): array => CheckoutAccountData::ofAccount(
                $account,
                $this->registry->forProvider($account->provider),
            )->toArray())->values()->all(),
            'paymentStatus' => $latestPayment?->status->value,
            // #131, whole-branch review finding 2: the payment window this
            // order really has — sixty minutes from placement on a brand
            // that offers a hosted checkout, null on one that settles by
            // bank transfer. Said out loud here because
            // `ExpireRegistrationOrders` acts on it; a page that invited a
            // payment it was about to orphan was the defect.
            'holdUntil' => $hold?->toIso8601String(),
            'payable' => $refusal === null,
            'refusalReason' => $refusal,
            // ADR-0057 point 4: the day the money is owed back by, so a
            // reversed order reads as "owed again until …" rather than as an
            // ordinary unpaid one. Null for every other status.
            'repayDueOn' => $this->repayDueOn($order),
            // Compliance's consent control (GitLab #2) — see
            // `Settlement\CheckoutConsentSlots` for why it arrives as a slot
            // contribution rather than as props this controller computes.
            'consentSlots' => $consentSlots,
        ]);
    }

    public function start(Request $request, string $registration): SymfonyResponse
    {
        $order = $this->orderFor($registration);

        // #210, as in `show()`: no order means the money is waiting in the
        // cart, and the checkout that settles it starts there.
        if ($order === null) {
            return $this->cartHandoff($registration);
        }

        $consentSlots = $this->consentSlots->contributions();

        // GitLab #205: `show()` above is the gate a payer normally meets;
        // this is the one that holds when the version was published between
        // the render and the submit. Checked before validation, and the
        // remembered intent is this order's own pay page rather than the
        // POST — see `CartController::checkout()` for the same two reasons.
        $redirect = $this->consentRedirect(
            $request,
            $consentSlots,
            route('commerce.pay.show', ['registration' => $registration], absolute: false),
        );

        if ($redirect !== null) {
            return $redirect;
        }

        // Computed once and handed to `refusalReason()` below (review N5):
        // this method also needs the answer for its own hard 409, and
        // asking `Payment::…->exists()` twice per request cost one for
        // nothing.
        $paymentSucceeded = $this->paymentSucceededFor($order);

        // Every refusal is settled before validation (review 6 parked nit
        // B): a POST against an order whose window has lapsed has to answer
        // with that fact, not with an `accept_terms` error about a form the
        // page no longer shows.
        $refusal = $this->refusalReason($order, $this->holdFor($order), $paymentSucceeded);

        // ADR-0057 point 3: a reversal revives the receivable — the sale
        // stands and the amount is owed again, and `RecordPaymentSuccess`
        // settles a `payment_reversed` order exactly as it settles a pending
        // one. The pay link the payer's reversal mail carries ends here, so
        // refusing this status would make that link a 409.
        abort_unless(
            in_array($order->status, [OrderStatus::Pending, OrderStatus::PaymentReversed], true),
            409,
            'This order is not payable.',
        );
        // GitLab #5: a *succeeded* payment is what closes this page, not a
        // payment attempt. An order whose last attempt is `failed` or
        // `expired` is still pending and still holds what it reserved, so
        // this page takes another attempt for as long as the window lasts —
        // no new cart, no new order, one more `Payment` row per attempt
        // (`payments_provider_external_id_unique` is per provider session and
        // is untouched by that: each attempt gets its own).
        abort_if($paymentSucceeded, 409, 'This order is already settled.');

        // Only the window refusal can still be standing here, and it is a
        // sentence on the form rather than a 409 for the same reason
        // `startOrder()` gives: this order is linked from a mail, and a
        // payer who arrives a minute after the deadline deserves to read
        // why instead of meeting an error page (#131, finding 2).
        if ($refusal !== null) {
            throw ValidationException::withMessages(['account' => $refusal]);
        }

        // The AGB and the Widerrufsbelehrung are confirmed before the order
        // button, not after it (GitLab #2). `accepted` puts the error on
        // `accept_terms`, which is the field the control raised it from
        // (ADR-0055). Only demanded where something is published to accept.
        $validated = $request->validate($this->consentSlots->declarationRequired($consentSlots)
            ? ['account' => ['required', 'ulid'], 'accept_terms' => ['accepted']]
            : ['account' => ['required', 'ulid']]);

        $account = $this->offeredAccounts($order)->firstWhere('id', $validated['account']);

        // An id that is unknown, not offered, or another brand's all land
        // here alike: refused with a validation error on `account`, the
        // same as any other rejected form field (redirect-back-with-errors
        // for a normal request; Inertia's XHR client surfaces it to the
        // page's shared `errors` prop instead of following the redirect).
        if ($account === null) {
            throw ValidationException::withMessages([
                'account' => __('Choose one of the offered payment options.'),
            ]);
        }

        // Recorded before the payer leaves for the provider: the declaration
        // is what authorizes the payment, and a provider failure a moment
        // later must not lose the record that it was made. Compliance decides
        // which documents and which version — Commerce never writes a consent
        // row (ADR-0004).
        //
        // `$request->user()` is never null here: the route carries `auth`
        // since #2, precisely because a declaration that cannot be recorded
        // against a subject is not a declaration (see the route file).
        if ($this->consentSlots->declarationRequired($consentSlots)) {
            event(new CheckoutConsentGiven(
                brandId: $order->brand_id,
                subjectId: (string) $request->user()->getAuthIdentifier(),
                captureContext: 'checkout',
                acceptedAt: now()->toIso8601String(),
            ));
        }

        $adapter = $this->registry->forProvider($account->provider);

        // offeredAccounts() only returns accounts whose adapter implements
        // this — unreachable in practice, but narrows the type for PHPStan.
        abort_unless($adapter instanceof ProvidesHostedCheckout, 409, 'This brand takes no online payment.');

        $redirect = $adapter->startCheckout(
            $account,
            $order,
            route('commerce.pay.show', ['registration' => $registration]),
        );

        // A hard redirect out of the SPA to the provider's hosted page.
        return Inertia::location($redirect->url);
    }

    /**
     * The order-addressed pay page (GitLab #5): the way back to an order
     * whose payment failed, for a cart order that has no registration ULID
     * to be addressed by.
     *
     * Unlike `show()`, knowing the id is not what authorizes this — an
     * order id travels in mails and browser history, and a cart order's
     * page names what someone bought. The order's own buyer, or 404.
     */
    public function showOrder(Request $request, string $order): Response|RedirectResponse
    {
        $model = $this->payableOrderFor($request, $order);

        $ownPage = $this->registrationPageFor($model);

        if ($ownPage !== null) {
            return $ownPage;
        }

        $consentSlots = $this->consentSlots->contributions();
        $redirect = $this->consentRedirect(
            $request,
            $consentSlots,
            route('commerce.pay.order.show', ['order' => $model->getKey()], absolute: false),
        );

        if ($redirect !== null) {
            return $redirect;
        }

        $latestPayment = $this->latestPaymentFor($model);
        $hold = $this->holdFor($model);
        // Once per render (review 6 parked nit C): the answer carries a
        // `Payment::exists()` query, and asking it twice cost one for
        // nothing.
        $refusal = $this->refusalReason($model, $hold);

        return Inertia::render('pay/Order', [
            'order' => [
                'id' => $model->getKey(),
                'status' => $model->status->value,
                'currency' => $model->currency,
                'totalNet' => $model->total_net,
                'totalTax' => $model->total_tax,
                'totalGross' => $model->total_gross,
                'lines' => $model->lines->map(fn (OrderLine $line): array => [
                    'label' => $line->label,
                    'quantity' => $line->quantity,
                    'lineGross' => $line->line_gross,
                ])->all(),
            ],
            'accounts' => $this->offeredAccounts($model)->map(fn (SettlementAccount $account): array => CheckoutAccountData::ofAccount(
                $account,
                $this->registry->forProvider($account->provider),
            )->toArray())->values()->all(),
            'paymentStatus' => $latestPayment?->status->value,
            // The deadline the *first* placement fixed, never renewed by a
            // retry (review 6 finding 3) — null once it has passed, and the
            // page then says the reservation is gone rather than offering a
            // button that cannot help.
            'holdUntil' => $hold?->toIso8601String(),
            'payable' => $refusal === null,
            'refusalReason' => $refusal,
            'consentSlots' => $consentSlots,
        ]);
    }

    /**
     * One more attempt on that order (GitLab #5). Nothing about the cart is
     * touched: no reuse branch, no `expires_at`, no second order — the
     * adapter is handed the very order that is already pending, and writes
     * one more `Payment` row for the attempt
     * (`payments_provider_external_id_unique` is per PSP session and is
     * untroubled by that).
     *
     * A refusal is a validation error on `account` naming the reason, not a
     * 409: this page is reached from a mail, and a payer who arrives one
     * minute after the reservation lapsed deserves a sentence rather than
     * an error page.
     */
    public function startOrder(Request $request, string $order): SymfonyResponse
    {
        $model = $this->payableOrderFor($request, $order);

        $ownPage = $this->registrationPageFor($model);

        if ($ownPage !== null) {
            return $ownPage;
        }

        $consentSlots = $this->consentSlots->contributions();

        $redirect = $this->consentRedirect(
            $request,
            $consentSlots,
            route('commerce.pay.order.show', ['order' => $model->getKey()], absolute: false),
        );

        if ($redirect !== null) {
            return $redirect;
        }

        // Before validation (review 6 parked nit B): a POST against an order
        // whose reservation has lapsed answered with an `accept_terms` error
        // about a form this page hides, instead of with the refusal itself.
        $refusal = $this->refusalReason($model, $this->holdFor($model));

        if ($refusal !== null) {
            throw ValidationException::withMessages(['account' => $refusal]);
        }

        $validated = $request->validate($this->consentSlots->declarationRequired($consentSlots)
            ? ['account' => ['required', 'ulid'], 'accept_terms' => ['accepted']]
            : ['account' => ['required', 'ulid']]);

        $account = $this->offeredAccounts($model)->firstWhere('id', $validated['account']);

        if ($account === null) {
            throw ValidationException::withMessages([
                'account' => __('Choose one of the offered payment options.'),
            ]);
        }

        if ($this->consentSlots->declarationRequired($consentSlots)) {
            event(new CheckoutConsentGiven(
                brandId: $model->brand_id,
                subjectId: (string) $request->user()->getAuthIdentifier(),
                captureContext: 'checkout',
                acceptedAt: now()->toIso8601String(),
            ));
        }

        $adapter = $this->registry->forProvider($account->provider);

        abort_unless($adapter instanceof ProvidesHostedCheckout, 409, 'This brand takes no online payment.');

        $redirectToProvider = $adapter->startCheckout(
            $account,
            $model,
            route('commerce.pay.order.show', ['order' => $model->getKey()]),
        );

        return Inertia::location($redirectToProvider->url);
    }

    /**
     * The order this request may pay, or 404. The buyer check is the
     * authorization (see `showOrder()`).
     */
    private function payableOrderFor(Request $request, string $order): Order
    {
        /** @var Order|null $model */
        $model = Order::query()->with('lines')->whereKey($order)->first();

        abort_if($model === null, 404);
        abort_unless($model->payer_user_id === (string) $request->user()->getAuthIdentifier(), 404);

        return $model;
    }

    /**
     * A registration order's own public checkout page, or null when this is
     * not a registration order (whole-branch review finding 7, review 6
     * parked nit A).
     *
     * A direct registration order carries a payer
     * (`CreateOrderOnSettlementRequested` passes the registrant), so the
     * buyer check above passes and this page used to render one order at
     * two payable URLs that refused it differently — `abort(409)` on one,
     * a form error on the other, and only one of them showing the deadline.
     * The order-addressed pair exists for a cart order (ADR-0047), which
     * has no registration ULID to be addressed by; a registration order has
     * one, and `ADR-0039`'s page is where it is paid.
     */
    private function registrationPageFor(Order $order): ?RedirectResponse
    {
        if ($order->settles_type !== 'registration') {
            return null;
        }

        return redirect()->route('commerce.pay.show', ['registration' => $order->settles_id]);
    }

    /**
     * Why this order cannot be paid right now, in the payer's own words, or
     * null when it can. Three refusals and no others: the order is no
     * longer owed, a payment already succeeded, or the reservation the
     * first placement fixed has run out.
     *
     * `$paymentSucceeded`, when the caller already knows the answer (`start()`
     * asks the same question again for its own hard 409), skips the repeat
     * `Payment::…->exists()` query rather than running it twice per request.
     */
    private function refusalReason(Order $order, ?CarbonInterface $hold, ?bool $paymentSucceeded = null): ?string
    {
        // ADR-0057 point 3: a reversed order is owed again and stays payable.
        if (! in_array($order->status, [OrderStatus::Pending, OrderStatus::PaymentReversed], true)) {
            return __('This order is no longer awaiting payment.');
        }

        if ($paymentSucceeded ?? $this->paymentSucceededFor($order)) {
            return __('This order is already settled.');
        }

        // A cart order's hold is what reserves its stock and its
        // registration lines (ADR-0047 point 4). Past it, `ExpireCarts` is
        // about to cancel the order, and taking money for it would sell
        // what has just been given back. A cart order with no cart left has
        // nothing to reserve either, so a missing hold refuses too.
        if ($order->settles_type === 'cart' && ($hold === null || $hold->isPast())) {
            return __('The reservation for this order has run out.');
        }

        // #131, whole-branch review finding 2: a *direct* registration
        // order has no cart, and until this branch nothing on the payer's
        // side knew it had a deadline at all —
        // `ExpireRegistrationOrders` cancelled it sixty minutes after
        // placement while this page still offered a pay button. A missing
        // window is not a refusal here, unlike the cart arm: a
        // bank-transfer brand's order genuinely has none and stays payable
        // for as long as the transfer takes.
        if ($order->settles_type === 'registration' && $this->paymentWindow->hasClosed($hold)) {
            return __('The payment window for this order has run out.');
        }

        return null;
    }

    /**
     * The deadline this order carries, or null when it has none.
     *
     * Read, never written: a cart order's hold is fixed by the first
     * placement and a retry does not renew it (review 6 finding 3), and a
     * registration order's window runs from the moment the order was placed.
     * `Settlement\PaymentWindow` owns the rule for both, so no page can
     * promise a deadline `ExpireRegistrationOrders` does not keep.
     */
    private function holdFor(Order $order): ?CarbonInterface
    {
        return $this->paymentWindow->endsFor($order);
    }

    private function latestPaymentFor(Order $order): ?Payment
    {
        return Payment::query()
            ->where('order_id', $order->id)
            ->latest('created_at')
            ->first();
    }

    private function paymentSucceededFor(Order $order): bool
    {
        return Payment::query()->where('order_id', $order->id)->where('status', PaymentStatus::Succeeded)->exists();
    }

    /**
     * The consent screen when this payer owes agreement to a contract text,
     * null when they do not (GitLab #205). `$intended` is the page they are
     * on, never the POST they sent: agreeing has to land them back here,
     * and a consent screen may not replay a purchase.
     *
     * @param  list<array{module: string, component: string, props: array<string, mixed>}>  $consentSlots
     */
    private function consentRedirect(Request $request, array $consentSlots, string $intended): ?RedirectResponse
    {
        $outstandingUrl = $this->consentSlots->outstandingUrl($consentSlots);

        if ($outstandingUrl === null) {
            return null;
        }

        $request->session()->put('url.intended', $intended);

        return redirect()->to($outstandingUrl);
    }

    /**
     * The day a reversed order's money is owed back by (ADR-0057 point 4):
     * the newest final reversal's `repay_due_on`, the same rule the operator
     * console reads through `repaymentDeadline()`. An open dispute carries
     * none — the provider still holds the money and nothing is owed yet.
     *
     * Brand-scoped implicitly, like `orderFor()`: this runs on the brand host.
     */
    private function repayDueOn(Order $order): ?string
    {
        if ($order->status !== OrderStatus::PaymentReversed) {
            return null;
        }

        return PaymentReversal::query()
            ->where('order_id', $order->id)
            ->where('status', ReversalStatus::Final)
            ->whereNotNull('repay_due_on')
            ->orderByDesc('occurred_at')
            ->first()?->repay_due_on?->toDateString();
    }

    /**
     * The latest order settling this registration: ADR-0036 makes "one
     * order per registration" false, and the payable one is the newest.
     * Brand-scoped implicitly — this runs on the brand host.
     *
     * Null rather than a `firstOrFail()` 404 since #210: with
     * `commerce:shop` on there may be no order at all and the money may
     * still be owed, in the cart (ADR-0047 point 3). `cartHandoff()` is
     * what decides between those two.
     */
    private function orderFor(string $registration): ?Order
    {
        return Order::query()
            ->where('settles_type', 'registration')
            ->where('settles_id', $registration)
            ->latest('created_at')
            ->first();
    }

    /**
     * Where a registration with no order is paid (#210).
     *
     * With `commerce:shop` on, an `instant` settlement request became a
     * cart line rather than an order (ADR-0047 point 3), and this route —
     * the one the registrant's mail and the settlement slot used to point
     * at — answered a blank 404 to a person who wanted to pay. It now hands
     * them to the cart, which holds the totals, the consent block and the
     * checkout button.
     *
     * Only a *live* line redirects: an expired or checked-out cart no
     * longer has anything payable here, and sending the payer to an empty
     * cart would be a second dead end.
     *
     * Those, and a genuinely unknown registration, go back to the
     * registration page carrying the sentence — not `abort(404, $message)`.
     * The production error page is `errors::404`, which yields a hard-coded
     * `__('Not Found')` into `errors::minimal`: the message handed to
     * `abort()` is rendered nowhere and the person is told nothing at all.
     * A redirect with a flash is the only shape that actually reaches them.
     * `Route::has()` because Registration's account routes are its own to
     * publish; without them the bare 404 is still better than a redirect to
     * nowhere.
     */
    private function cartHandoff(string $registration): RedirectResponse
    {
        $liveLine = CartLine::query()
            ->where('kind', CartLineKind::Registration)
            ->where('ref_id', $registration)
            ->whereIn('cart_id', Cart::query()->open()->where('expires_at', '>', now())->select('id'))
            ->exists();

        if (! $liveLine) {
            abort_unless(Route::has('registration.mine.show'), 404, __('No payment is open for this registration.'));

            Inertia::flash('toast', ['type' => 'info', 'message' => __('No payment is open for this registration.')]);

            return redirect()->route('registration.mine.show', ['registration' => $registration]);
        }

        Inertia::flash('toast', ['type' => 'info', 'message' => __('This registration is waiting in your cart — check out there.')]);

        return redirect()->route('commerce.cart.show');
    }

    /**
     * Every account the payer may choose from (ADR-0046): live, offered,
     * and able to send the payer to a hosted checkout page.
     *
     * @return Collection<int, SettlementAccount>
     */
    private function offeredAccounts(Order $order): Collection
    {
        return SettlementAccount::liveAccountsFor($order->brand_id)
            ->filter(fn (SettlementAccount $account): bool => $account->isOffered()
                && $this->registry->forProvider($account->provider) instanceof ProvidesHostedCheckout)
            ->values();
    }
}
