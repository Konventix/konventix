<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A settlement attempt against an order (ADR-0039). The order stays the
     * authoritative money record (ADR-0006); a payment is one attempt to
     * settle it — created `pending` when a hosted checkout starts, moved to
     * `succeeded`/`failed`/`expired` exclusively by a verified webhook.
     */
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table): void {
            // ULID primary key — ADR-0014.
            $table->ulid('id')->primary();

            // Brand-owned — ADR-0010.
            $table->foreignUlid('brand_id')->constrained()->cascadeOnDelete();

            // Same-module reference, still plain ULID (ADR-0014): payments
            // are written from stateless webhook requests, and a dangling FK
            // error there would surface as a provider retry storm.
            $table->ulid('order_id');

            // Which connected account settled this (ADR-0007). Null for a
            // manually booked payment, which involves no PSP account at all.
            $table->ulid('settlement_account_id')->nullable();

            $table->string('provider', 32);

            // pending | succeeded | failed | expired — PaymentStatus.
            $table->string('status', 16);

            $table->decimal('amount', 12, 2);
            $table->string('currency', 3);

            // The provider's identifier for this payment (Stripe checkout
            // session id, Mollie payment id, Unzer payment id). Null only
            // for manual bookings.
            $table->string('external_id')->nullable();

            // Provider-reported reason on failure/expiry, for the operator.
            $table->string('failure_reason')->nullable();

            $table->timestamp('received_at')->nullable();
            $table->timestamp('failed_at')->nullable();

            $table->timestamps();

            $table->index(['brand_id', 'order_id']);
            $table->index(['order_id', 'status']);
        });

        // Webhook idempotency (ADR-0039): a replayed or forged notification
        // for the same provider payment converges on one row instead of
        // settling an order twice. Partial — manual bookings have no
        // external id and may repeat freely.
        DB::statement(
            'CREATE UNIQUE INDEX payments_provider_external_id_unique'
            .' ON payments (provider, external_id)'
            .' WHERE external_id IS NOT NULL',
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
