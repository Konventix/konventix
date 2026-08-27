<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ADR-0048: a paid order gets a Rechnung the moment it is paid, and an
     * invoice needs a billing party to address. Registration's settlement
     * request already carried one (`BillingPartyData::ofEventPayload`), but
     * only the invoice branch of `PlaceOrderFromSettlementRequest` kept it —
     * a card-paid order had nowhere to snapshot it for later. Nullable: an
     * order predating this column, or one an operator books manually with
     * no billing information, has no party to name — `IssueInvoiceOnOrderPaid`
     * treats that as "nothing to issue", not an error.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->json('billing_party')->nullable()->after('payer_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('billing_party');
        });
    }
};
