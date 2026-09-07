<?php

declare(strict_types=1);

namespace Konventix\Commerce\Http\Controllers;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Konventix\Commerce\Data\CheckoutAccountData;
use Konventix\Commerce\Enums\OrderStatus;
use Konventix\Commerce\Enums\PaymentStatus;
use Konventix\Commerce\Events\CheckoutConsentGiven;
use Konventix\Commerce\Models\Order;
use Konventix\Commerce\Models\OrderLine;
use Konventix\Commerce\Models\Payment;
use Konventix\Commerce\Models\SettlementAccount;
use Konventix\Commerce\Settlement\ProvidesHostedCheckout;
use Konventix\Commerce\Settlement\SettlementAdapterRegistry;
use Konventix\Platform\Slots\SlotRegistry;
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
 */
class CheckoutController
{
    public function __construct(
        private readonly SettlementAdapterRegistry $registry,
        private readonly SlotRegistry $slots,
    ) {}

    public function show(string $registration): Response
    {
        $order = $this->orderFor($registration);

        $latestPayment = Payment::query()
            ->where('order_id', $order->id)
            ->latest('created_at')
            ->first();

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
            // Compliance's consent control (GitLab #2) — see
            // `CartController::consentSlots()` for why it arrives as a slot
            // contribution rather than as props this controller computes.
            'consentSlots' => $this->consentSlots(),
        ]);
    }

    public function start(Request $request, string $registration): SymfonyResponse
    {
        $order = $this->orderFor($registration);
        $consentSlots = $this->consentSlots();

        // The AGB and the Widerrufsbelehrung are confirmed before the order
        // button, not after it (GitLab #2). `accepted` puts the error on
        // `accept_terms`, which is the field the control raised it from
        // (ADR-0055). Only demanded where something is published to accept.
        $validated = $request->validate($this->consentRequired($consentSlots)
            ? ['account' => ['required', 'ulid'], 'accept_terms' => ['accepted']]
            : ['account' => ['required', 'ulid']]);

        abort_unless($order->status === OrderStatus::Pending, 409, 'This order is not payable.');
        abort_if(
            Payment::query()->where('order_id', $order->id)->where('status', PaymentStatus::Succeeded)->exists(),
            409,
            'This order is already settled.',
        );

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
        if ($this->consentRequired($consentSlots)) {
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
     * Compliance's contribution to the consent control, resolved through
     * Platform's slot registry — same shape and same reason as
     * `CartController::consentSlots()`.
     *
     * @return list<array{module: string, component: string, props: array<string, mixed>}>
     */
    private function consentSlots(): array
    {
        return array_values($this->slots->for('checkout.consent')
            ->map(fn (array $entry): array => [
                'module' => $entry['module'],
                'component' => $entry['component'],
                'props' => $entry['props'],
            ])
            ->all());
    }

    /**
     * Whether a contribution says the payer must confirm it before the order
     * is placed. Same as `CartController::consentRequired()`.
     *
     * @param  list<array{module: string, component: string, props: array<string, mixed>}>  $consentSlots
     */
    private function consentRequired(array $consentSlots): bool
    {
        foreach ($consentSlots as $slot) {
            if (($slot['props']['required'] ?? false) === true) {
                return true;
            }
        }

        return false;
    }

    /**
     * The latest order settling this registration: ADR-0036 makes "one
     * order per registration" false, and the payable one is the newest.
     * Brand-scoped implicitly — this runs on the brand host.
     */
    private function orderFor(string $registration): Order
    {
        return Order::query()
            ->where('settles_type', 'registration')
            ->where('settles_id', $registration)
            ->latest('created_at')
            ->firstOrFail();
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
