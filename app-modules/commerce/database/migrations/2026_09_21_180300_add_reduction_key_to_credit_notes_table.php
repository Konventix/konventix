<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which partial cancellation this credit note documents, if any
     * (D-REG-15, #7). Null for every other credit note — a whole refund, a
     * billing-party transfer (ADR-0036), the cancellation of an order whose
     * reversed payment went unpaid (ADR-0057 point 4).
     *
     * This is the money side's idempotency key, and it is on the credit
     * note because the credit note is the one artefact a partial refund
     * always writes. `RefundOrder` reads it before calling the provider, so
     * a replayed `RegistrationQuantityReduced` cannot send the money twice,
     * and `RecordRefund` reads it again under the order lock, so two
     * concurrent replays cannot either.
     *
     * Registration owns the reduction, so this is a plain ULID with no FK
     * constraint (ADR-0014). The unique key is per brand, matching every
     * other lookup in this module.
     */
    public function up(): void
    {
        Schema::table('credit_notes', function (Blueprint $table): void {
            $table->ulid('reduction_key')->nullable()->after('reason_note');

            $table->unique(['brand_id', 'reduction_key']);
        });
    }

    public function down(): void
    {
        Schema::table('credit_notes', function (Blueprint $table): void {
            $table->dropUnique(['brand_id', 'reduction_key']);
            $table->dropColumn('reduction_key');
        });
    }
};
