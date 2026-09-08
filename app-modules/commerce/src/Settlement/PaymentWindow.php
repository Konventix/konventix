<?php

declare(strict_types=1);

namespace Konventix\Commerce\Settlement;

use Carbon\CarbonInterface;
use Konventix\Commerce\Enums\OrderStatus;
use Konventix\Commerce\Models\Cart;
use Konventix\Commerce\Models\Invoice;
use Konventix\Commerce\Models\Order;
use Konventix\Commerce\Models\SettlementAccount;

/**
 * How long an unpaid order stays payable, for every kind of order there is
 * (GitLab #131, whole-branch review finding 2).
 *
 * One answer, because four callers ask it — the two pay pages, the order
 * confirmation, the payment-failed mail — and the sweep that acts on it
 * (`Actions\ExpireRegistrationOrders`). Before this class the sweep knew
 * the deadline and nothing the payer could see did, so a registration
 * order was invited to be paid right up to the minute it was cancelled and
 * past it: `RecordPaymentSuccess` then refuses to settle the cancelled
 * order and records `PaymentUnapplied`, and the money sits stranded until
 * an operator notices (the hazard filed as #231 for the cart sweep).
 *
 * Two kinds of order, two sources for the same fact:
 *
 * - a **cart** order's window is its checked-out cart's `expires_at`, set
 *   when the payer was sent to the PSP — and **renewed by a retry**:
 *   `PlaceOrderFromCart` reuses a checked-out cart's still-pending order
 *   and pushes `expires_at` a fresh grace forward every time the payer
 *   starts another attempt, so the deadline this class states is always the
 *   current attempt's (ADR-0047 point 4, `ExpireCarts`, finding 5 — the
 *   docblock here used to claim the opposite). Read whatever the order's status is
 *   — the pay pages check the status separately and a reversed order's
 *   revived receivable must not be refused for a cart that expired long
 *   ago.
 * - a **direct registration** order's window is `MINUTES` from placement,
 *   and only where `ExpireRegistrationOrders` would really take it: the
 *   order is still pending, nobody was invoiced for it (an invoice is a
 *   receivable with payment terms, ADR-0031, and the dunning ladder owns
 *   it), and the brand could have put the payer in front of a hosted
 *   checkout at all. A bank-transfer brand's order has no window: a
 *   transfer takes days and `pay/Show.vue` says so.
 *
 * The registration arm is deliberately the sweep's own predicate rather
 * than a restatement of it, so a page can never promise a deadline the
 * sweep does not keep, or hide one it does.
 *
 * Stateless apart from the adapter registry — safe as a plain container
 * resolution under Octane.
 */
final class PaymentWindow
{
    /**
     * The window a direct registration order gets: the carts' own checkout
     * grace (#131, "dieselbe Frist wie Warenkoerbe"), read off `Cart`
     * rather than restated so the two cannot drift.
     */
    public const int MINUTES = Cart::CHECKOUT_GRACE_MINUTES;

    private const string SETTLES_REGISTRATION = 'registration';

    public function __construct(private readonly SettlementAdapterRegistry $registry) {}

    /**
     * The moment this order stops being payable, or null when it has no
     * window at all.
     *
     * `withoutBrandScope()` throughout (ADR-0010): the mails and the sweep
     * reach this from a queued job and a console command, where no brand is
     * resolved from a host.
     */
    public function endsFor(Order $order): ?CarbonInterface
    {
        if ($order->settles_type !== self::SETTLES_REGISTRATION) {
            return Cart::withoutBrandScope()
                ->where('checked_out_order_id', $order->getKey())
                ->first()?->expires_at;
        }

        if ($order->status !== OrderStatus::Pending || $order->created_at === null) {
            return null;
        }

        if (Invoice::withoutBrandScope()->where('order_id', $order->getKey())->exists()) {
            return null;
        }

        if (! $this->brandOffersHostedCheckout($order->brand_id)) {
            return null;
        }

        return $order->created_at->copy()->addMinutes(self::MINUTES);
    }

    /**
     * Whether that window has closed. `<=` rather than `<` because the
     * sweep's own predicate is `created_at <= now() - MINUTES`: at the
     * exact boundary the sweep cancels, so the page must already refuse.
     */
    public function hasClosed(?CarbonInterface $endsAt): bool
    {
        return $endsAt !== null && $endsAt->lessThanOrEqualTo(now());
    }

    /**
     * Whether this brand could have put a payer in front of a hosted
     * checkout at all — the same question `CheckoutController::offeredAccounts()`
     * asks before it renders a pay button, asked here so no sweep and no
     * page ever puts a sixty-minute deadline on an order the payer was told
     * to settle by bank transfer.
     */
    public function brandOffersHostedCheckout(string $brandId): bool
    {
        return SettlementAccount::liveAccountsFor($brandId)
            ->contains(fn (SettlementAccount $account): bool => $this->isHostedCheckoutAccount($account));
    }

    /**
     * The same question asked of every brand at once, so the sweep can put
     * the predicate in its `WHERE` instead of streaming every registration
     * order ever placed and discarding most of them in PHP (whole-branch
     * review finding 4).
     *
     * One query over `settlement_accounts`, which holds a handful of rows
     * per brand — cheap enough to compute up front, and it lets Postgres
     * exclude a bank-transfer brand's orders rather than the sweep.
     *
     * @return list<string>
     */
    public function brandIdsOfferingHostedCheckout(): array
    {
        $brandIds = SettlementAccount::withoutBrandScope()
            ->whereNull('disconnected_at')
            ->whereNotNull('connected_at')
            ->get()
            ->filter(fn (SettlementAccount $account): bool => $this->isHostedCheckoutAccount($account))
            ->map(fn (SettlementAccount $account): string => $account->brand_id)
            ->unique()
            ->values()
            ->all();

        return array_values($brandIds);
    }

    private function isHostedCheckoutAccount(SettlementAccount $account): bool
    {
        return $account->isOffered()
            && $this->registry->forProvider($account->provider) instanceof ProvidesHostedCheckout;
    }
}
