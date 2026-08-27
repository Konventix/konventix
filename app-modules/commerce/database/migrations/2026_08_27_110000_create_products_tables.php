<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The shop's first data (ADR-0047, roadmap slice 24): a `Product` sold
     * independent of a registration, and its `ProductVariant`s.
     *
     * `event_id` on `products` is a plain nullable ULID, not a foreign key
     * (ADR-0014): a product may tie to one edition — "this year's shirt" —
     * but Commerce never reads Event's tables for it.
     *
     * Stock is a quota in the Registration sense (`Quota`'s contract, copied
     * not imported): `stock_capacity`/`stock_consumed` are counters mutated
     * only under `lockForUpdate()` by the caller's own transaction. `sku` is
     * nullable — a product without variation still gets one implicit
     * `ProductVariant` row (Task 7) — and unique per brand only when set, so
     * many implicit variants with no sku never collide.
     */
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('brand_id')->constrained()->cascadeOnDelete();
            // Optional tie to an event by plain ULID (ADR-0014): merch for one
            // edition. Commerce never reads Event's tables for it.
            $table->ulid('event_id')->nullable();
            $table->string('name', 120);
            $table->string('slug', 80);
            $table->text('description')->nullable();
            $table->ulid('image_media_id')->nullable();
            $table->string('tax_class', 16);
            $table->decimal('unit_amount_net', 12, 2);
            $table->string('price_display', 8);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->unique(['brand_id', 'slug']);
            $table->index(['brand_id', 'event_id']);
        });

        Schema::create('product_variants', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('brand_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('product_id')->constrained()->cascadeOnDelete();
            // Null for the implicit variant of a product without sizes.
            $table->string('label', 60)->nullable();
            $table->string('sku', 60)->nullable();
            $table->decimal('unit_amount_net', 12, 2)->nullable();
            // Stock is a quota in the Registration sense: capacity and a
            // consumed counter, mutated only under lockForUpdate().
            $table->unsignedInteger('stock_capacity');
            $table->unsignedInteger('stock_consumed')->default(0);
            $table->unsignedSmallInteger('position');
            $table->timestamps();
            $table->index(['product_id', 'position']);
        });
        DB::statement('CREATE UNIQUE INDEX product_variants_sku_per_brand ON product_variants (brand_id, sku) WHERE sku IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variants');
        Schema::dropIfExists('products');
    }
};
