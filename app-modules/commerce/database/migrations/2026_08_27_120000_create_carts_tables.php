<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The cart and its reservation (ADR-0047, Task 9): a person-owned root
     * that locks stock as a quota for twenty minutes
     * (`Cart::LIFETIME_MINUTES`), the first Commerce entity that belongs to
     * a person before any order exists.
     *
     * `carts_one_open_per_payer` is a partial unique index, not an
     * application check — the same reasoning ADR-0020's exclusion
     * constraint and `entrants_one_live_per_team` already apply here: a
     * double-tapped "add to cart" is exactly the race a read-then-write
     * check loses, so `OpenCart::for()` attempts the insert and lets the
     * constraint refuse the second one.
     *
     * `cart_lines.kind` is `product` or `registration` (ADR-0047 point 3):
     * a registration joins the cart by submitting first, keyed by its own
     * `request_key`, which is why `cart_lines_request_key_unique` is
     * likewise partial — most lines (every product line) carry no
     * `request_key` at all.
     *
     * `order_lines.settles_kind`/`settles_ref` ride along in this same
     * migration rather than a separate one: they are the columns
     * `GuardsProductVariantReferences::isReferenced()` needs to see a
     * settled order line at all, and Task 9 is what gives that guard its
     * teeth.
     */
    public function up(): void
    {
        Schema::create('carts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('brand_id')->constrained()->cascadeOnDelete();
            // Not a foreign key to Identity's users table — same reasoning
            // as `orders.payer_user_id` (ADR-0044): a plain ULID reference,
            // never an Eloquent relation across the module boundary.
            $table->ulid('payer_user_id');
            $table->string('status', 16);
            $table->timestamp('expires_at');
            $table->ulid('checked_out_order_id')->nullable();
            $table->timestamps();
            $table->index(['status', 'expires_at']);
        });
        DB::statement('CREATE UNIQUE INDEX carts_one_open_per_payer ON carts (brand_id, payer_user_id) WHERE status = \'open\'');

        Schema::create('cart_lines', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('brand_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('cart_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 16);
            // A `ProductVariant` id or a settlement request's id, depending
            // on `kind` — never an Eloquent relation (ADR-0014); Commerce
            // owns the product half itself, but a same-module FK would
            // still be wrong here since one column serves two referents.
            $table->ulid('ref_id');
            // Nullable — set only for a registration line, which joins the
            // cart keyed by the settlement request that created it.
            $table->ulid('request_key')->nullable();
            $table->string('label');
            $table->unsignedInteger('quantity');
            $table->decimal('unit_amount_net', 12, 2);
            $table->string('tax_class', 16);
            $table->unsignedSmallInteger('position');
            $table->timestamps();
            $table->index(['cart_id', 'kind', 'ref_id']);
        });
        DB::statement('CREATE UNIQUE INDEX cart_lines_request_key_unique ON cart_lines (cart_id, kind, request_key) WHERE request_key IS NOT NULL');

        Schema::table('order_lines', function (Blueprint $table): void {
            $table->string('settles_kind', 32)->nullable()->after('label');
            $table->ulid('settles_ref')->nullable()->after('settles_kind');
        });
    }

    public function down(): void
    {
        Schema::table('order_lines', function (Blueprint $table): void {
            $table->dropColumn(['settles_kind', 'settles_ref']);
        });

        DB::statement('DROP INDEX IF EXISTS cart_lines_request_key_unique');
        Schema::dropIfExists('cart_lines');

        DB::statement('DROP INDEX IF EXISTS carts_one_open_per_payer');
        Schema::dropIfExists('carts');
    }
};
