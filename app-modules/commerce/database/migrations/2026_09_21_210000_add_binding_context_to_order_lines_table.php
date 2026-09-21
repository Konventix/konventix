<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Task 32 / D-CPL-06 (spec §7.2): the opaque bag a settlement request
     * brought with it.
     *
     * Commerce must hand Compliance a context containing registration types
     * and entry conditions, and Commerce may not read Registration's tables
     * (ADR-0004). So `RegistrationSettlementRequested` carries a
     * `binding_context` of scalars, and it is stored here, on the line the
     * request produced, **uninterpreted**: no index, no cast beyond `array`,
     * no column this module ever filters on. Commerce carries foreign ULIDs,
     * which ADR-0004 permits; Compliance is still the only module that knows
     * what any of them mean.
     *
     * On `cart_lines` too, because an `instant` settlement on a brand with
     * `commerce:shop` becomes a cart line first (ADR-0047 point 3) and
     * `PlaceOrderFromCart` copies it onto the order line at checkout. A bag
     * that stopped at the cart would leave the shop's whole purchase path
     * resolving unbound policies only.
     *
     * **No backfill.** Lines that exist at deploy time carry null and
     * resolve unbound policies only — exactly what they do today. A
     * migration that invented a context for a purchase nobody was asked
     * about would manufacture evidence.
     */
    public function up(): void
    {
        Schema::table('order_lines', function (Blueprint $table): void {
            $table->json('binding_context')->nullable();
        });

        Schema::table('cart_lines', function (Blueprint $table): void {
            $table->json('binding_context')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('order_lines', function (Blueprint $table): void {
            $table->dropColumn('binding_context');
        });

        Schema::table('cart_lines', function (Blueprint $table): void {
            $table->dropColumn('binding_context');
        });
    }
};
