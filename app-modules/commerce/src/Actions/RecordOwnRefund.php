<?php

declare(strict_types=1);

namespace Konventix\Commerce\Actions;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Konventix\Commerce\Actions\Concerns\LocksMoneyRowsInOneOrder;
use Konventix\Commerce\Enums\ReversalKind;
use Konventix\Commerce\Enums\ReversalStatus;
use Konventix\Commerce\Models\Order;
use Konventix\Commerce\Models\Payment;
use Konventix\Commerce\Models\PaymentReversal;
use RuntimeException;

/**
 * The `payment_reversals` row for a refund **Konventix itself** made through
 * the provider (#247): `ReversalKind::Refund`, final on arrival, carrying
 * the reference the provider assigned the money going back.
 *
 * Written directly rather than through `RecordPaymentReversal`, and that is
 * the whole point of it being its own Action: `RecordPaymentReversal` exists
 * to *react* to money leaving — revive the receivable, or undo the sale —
 * and both callers here have already done everything that follows from this
 * refund. Running the reversal reactions on top would refund the refund.
 *
 * Two callers: `RecordRefund` (the ordinary refund of a paid order, inside
 * its own transaction beside the credit note and the status) and
 * `RefundOrder`'s unapplied path (ADR-0057 point 9, #6 — money that arrived
 * for an order nobody could apply it to, sent back with no document to
 * correct).
 *
 * Its second job is convergence. The provider announces our own refund back
 * to us — Stripe's `charge.refunded`, and the reconciliation sweep of #235 —
 * and both name this reference; the unique `(provider, provider_reference)`
 * key makes them find this row instead of recording an `external_refund`
 * against an order we refunded ourselves.
 *
 * **And it converges in both directions** (fix round 1, review finding 5).
 * `charge.refunded` can be delivered between the refund call returning
 * `re_…` and `RecordRefund`'s transaction committing, so the row may already
 * exist by the time this runs: it is looked up first, and an insert that
 * still loses the race reads the webhook's row back instead of letting a
 * unique violation roll back a credit note for money already with the payer.
 *
 * Escapes BrandScope by taking `brand_id` from the order rather than from
 * ambient context (ADR-0010).
 */
final class RecordOwnRefund
{
    use LocksMoneyRowsInOneOrder;

    public function handle(
        Order $order,
        Payment $payment,
        string $amount,
        string $providerReference,
    ): PaymentReversal {
        // Order, then payment, then the reversal row
        // (`LocksMoneyRowsInOneOrder`, review NEW-5). `RecordRefund` and
        // `RefundOrder`'s unapplied path both hold the order lock by the time
        // they reach this, and re-taking a row this transaction already holds
        // costs nothing — saying it here is what keeps the sequence one rule
        // rather than four call sites that happen to agree.
        $this->lockOrder($order->getKey());
        $this->lockPayment($payment->id);

        $existing = PaymentReversal::forProviderReference($payment->provider, $providerReference);

        if ($existing !== null) {
            return $existing;
        }

        $reversal = new PaymentReversal([
            'payment_id' => $payment->id,
            'order_id' => $order->id,
            'settlement_account_id' => $payment->settlement_account_id,
            'provider' => $payment->provider,
            'kind' => ReversalKind::Refund,
            'status' => ReversalStatus::Final,
            'amount' => $amount,
            'currency' => $order->currency,
            'provider_reference' => $providerReference,
            // The operator's reason lives on the credit note and the audit
            // entry; this row is about the money's identity at the provider.
            'reason_code' => null,
            // A refund costs no chargeback fee — that is what makes it the
            // cheaper answer to a disputed payment (ADR-0057 point 1).
            'fee_amount' => null,
            'occurred_at' => now(),
            'finalized_at' => now(),
        ]);

        // brand_id from the order, not ambient context (ADR-0010).
        $reversal->forceFill(['brand_id' => $order->brand_id]);

        try {
            // Its own nested transaction, which Postgres runs as a
            // SAVEPOINT: a unique violation aborts the statement *and* the
            // transaction it runs in, so without this the caller's
            // transaction — the credit note, the order's status — would be
            // unusable from here on even though we mean to recover. The
            // savepoint keeps the rollback to this insert.
            DB::transaction(fn () => $reversal->save());
        } catch (UniqueConstraintViolationException) {
            // The provider announced our own refund back to us while this
            // transaction was still open (fix round 1, review finding 5):
            // `charge.refunded` can be delivered between the API call
            // returning `re_…` and this commit. Converging on the row the
            // webhook wrote is the only acceptable answer — the money is
            // already back with the payer, so failing here would roll back a
            // refund that happened.
            $row = PaymentReversal::forProviderReference($payment->provider, $providerReference);

            if ($row === null) {
                throw new RuntimeException(sprintf(
                    'Refund reference [%s] on provider [%s] collided but cannot be read back.',
                    $providerReference,
                    $payment->provider,
                ));
            }

            return $row;
        }

        return $reversal;
    }
}
