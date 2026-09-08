<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * #218 (#216 draft i): a registration-kind cart line snapshots the sales
     * window its settlement request declared, the same way it already
     * snapshots `label`, `unit_amount_net` and `tax_class`.
     *
     * The snapshot is not a convenience — it is the only way the window can
     * be checked again at checkout. Commerce never reads Registration's
     * table (ADR-0004), so the window arrives once, as two nullable ISO-8601
     * scalars on `RegistrationSettlementRequested`, and has to be kept.
     * Without it a cart line would outlive the sale exactly as a product
     * line outlives `Product::published_at` today (#216).
     *
     * Instants, not wall-clock times: see the registration-side migration for
     * why a sales window is not an ADR-0027 event time. Null on either bound
     * means no bound, and both are null for every product line.
     */
    public function up(): void
    {
        Schema::table('cart_lines', function (Blueprint $table): void {
            $table->timestamp('sale_starts_at')->nullable();
            $table->timestamp('sale_ends_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('cart_lines', function (Blueprint $table): void {
            $table->dropColumn(['sale_starts_at', 'sale_ends_at']);
        });
    }
};
