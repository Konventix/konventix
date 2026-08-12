<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Order lines and the settlement-time tax snapshot (ADR-0017: Commerce
     * computes and snapshots; ADR-0030 for the treatment cases). The rate
     * on each line is the *resolved* rate at settlement time — a statutory
     * rate change never rewrites what was already sold.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->decimal('total_tax', 10, 2)->default(0);
            $table->string('tax_treatment')->default('standard');
            $table->string('price_display')->default('gross');
        });

        Schema::create('order_lines', function (Blueprint $table): void {
            // ULID primary key — ADR-0014.
            $table->ulid('id')->primary();

            // Brand-owned — ADR-0010.
            $table->foreignUlid('brand_id')->constrained()->cascadeOnDelete();

            // Same-module reference, real FK allowed (ADR-0014).
            $table->foreignUlid('order_id')->constrained()->cascadeOnDelete();

            $table->unsignedSmallInteger('position');
            $table->string('label');
            $table->unsignedInteger('quantity');
            $table->decimal('unit_amount_net', 10, 2);
            $table->string('tax_class');
            $table->decimal('tax_rate', 5, 2);
            $table->decimal('line_net', 10, 2);
            $table->decimal('line_tax', 10, 2);
            $table->decimal('line_gross', 10, 2);

            $table->timestamps();

            $table->unique(['order_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_lines');

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn(['total_tax', 'tax_treatment', 'price_display']);
        });
    }
};
