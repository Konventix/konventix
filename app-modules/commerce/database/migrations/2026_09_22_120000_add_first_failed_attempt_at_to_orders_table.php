<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When this order's payer first had an attempt refused (#249, operator
     * decision D-0.8.2-4 of 2026-09-22).
     *
     * The two release valves of #231 — a day for a provider that cannot be
     * reached, a fortnight for money it says is still travelling — counted
     * from the end of the order's payment window, which for a direct
     * registration order is `orders.created_at` plus an hour. An order
     * placed weeks ago that only starts failing today therefore reached
     * both sweeps with its valve already expired, and the first answer the
     * provider could not give cancelled it on the spot. The clock has to
     * start when the trouble started.
     *
     * Nullable for ever, not just for the backfill: an order whose payer
     * never had an attempt refused has no such moment, and
     * `HostedPaymentProbe` falls back to the payment window — which is
     * `orders.created_at` for the registration orders this defect was found
     * on — for every row written before this column existed.
     *
     * Stamped once, by `RecordPaymentFailure`, and never moved: the *first*
     * failure is what the decision names, so a payer who retries five times
     * does not push the valve out five times.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->timestamp('first_failed_attempt_at')->nullable()->after('paid_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('first_failed_attempt_at');
        });
    }
};
