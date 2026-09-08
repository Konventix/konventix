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
     * Commerce never reads Registration's table (ADR-0004), so the window
     * arrives once, as two nullable ISO-8601 scalars on
     * `RegistrationSettlementRequested`, and is kept here for display and
     * audit: what window was this line placed under.
     *
     * **It is a snapshot, and nothing refreshes it** (fix round 1). An
     * operator who extends `sales_end` for stragglers does not reach a line
     * already in a cart, and one who closes a sale early does not either.
     * That is why the checkout deliberately does not enforce it
     * (`PlaceOrderFromCart`): a line placed while the sale was open stays
     * payable within the cart's own hold, and the alternative would refuse
     * the helpful case with a 409 nobody could explain. Propagating a window
     * change as an event is the follow-up if that is ever wanted.
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
