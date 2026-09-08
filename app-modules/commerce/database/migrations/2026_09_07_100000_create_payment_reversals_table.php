<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Money that was already settled left again (ADR-0057 point 1). A
     * reversal is a record of its own rather than a state on the payment:
     * the provider reference, the reason and the fee have nowhere to live
     * on a status column, a dispute has a lifecycle a terminal status
     * cannot express, and a partial reversal fits no status at all.
     *
     * `PaymentStatus::Reversed` still exists beside it, written only when a
     * reversal becomes final for the payment's whole amount, so every
     * existing "did this pay?" question stays a one-column read.
     */
    public function up(): void
    {
        Schema::create('payment_reversals', function (Blueprint $table): void {
            // ULID primary key — ADR-0014.
            $table->ulid('id')->primary();

            // Brand-owned — ADR-0010.
            $table->foreignUlid('brand_id')->constrained()->cascadeOnDelete();

            // Same-module references, still plain ULIDs (ADR-0014), for the
            // reason `payments` gives: these rows are written from stateless
            // webhook requests, where a dangling FK error would surface as a
            // provider retry storm rather than as an error anyone reads.
            $table->ulid('payment_id');
            $table->ulid('order_id');

            // Which connected account the money left through (ADR-0007).
            // Null where the payment had none — a manual booking.
            $table->ulid('settlement_account_id')->nullable();

            $table->string('provider', 32);

            // return | dispute | external_refund — ReversalKind.
            $table->string('kind', 32);

            // open | final | withdrawn — ReversalStatus.
            $table->string('status', 16);

            // Same money shape as `payments.amount` and `orders.total_gross`
            // (ADR-0030).
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3);

            // The provider's own identifier for this reversal: a Stripe
            // dispute (`dp_…`) or refund (`re_…`), a Mollie chargeback
            // (`chb_…`), an Unzer chargeback transaction id.
            $table->string('provider_reference');

            // The provider's reason string, stored verbatim (ADR-0057
            // point 1) — bank and network codes, never translated here.
            $table->string('reason_code')->nullable();

            // The chargeback fee as the provider reports it. The
            // organizer's cost on their own statement (ADR-0007), never a
            // line on our invoice.
            $table->decimal('fee_amount', 12, 2)->nullable();

            $table->timestamp('occurred_at');
            $table->timestamp('finalized_at')->nullable();

            // ADR-0057 point 4: `occurred_at` plus the brand's repayment
            // window. Null while a dispute is open, and for a partial
            // reversal, which has no amount the order could be settled for
            // until #35 exists.
            $table->date('repay_due_on')->nullable();

            $table->timestamps();

            // Webhook idempotency (ADR-0057 point 1, the shape ADR-0039
            // point 3 gave payments): a replayed notification converges on
            // one row instead of reversing an order twice. Both columns are
            // NOT NULL, so a plain unique index says all of it.
            $table->unique(['provider', 'provider_reference']);

            // The operator console's "open reversals" list, per brand.
            $table->index(['brand_id', 'status']);
            $table->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_reversals');
    }
};
