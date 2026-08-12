<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Invoices and per-brand gapless number ranges (ADR-0006 "per-brand
     * invoice number ranges", ADR-0031). An invoice row is immutable once
     * issued — corrections are credit notes or new invoices, never edits.
     * `billing_party` and `seller` are point-in-time snapshots (typed Data
     * casts, ADR-0009): the document must stay renderable exactly as issued
     * even if the operator's address or the registrant's data changes later.
     *
     * The number range is one row per brand and year (counter resets
     * yearly, format PREFIX-YYYY-NNNNN — Phase 2 interview decision),
     * consumed under `lockForUpdate` so numbers are gapless and unique.
     */
    public function up(): void
    {
        Schema::create('invoice_number_ranges', function (Blueprint $table): void {
            // ULID primary key — ADR-0014.
            $table->ulid('id')->primary();

            // Brand-owned — ADR-0010.
            $table->foreignUlid('brand_id')->constrained()->cascadeOnDelete();

            $table->string('prefix', 12);
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('counter')->default(0);

            $table->timestamps();

            $table->unique(['brand_id', 'year']);
        });

        Schema::create('invoices', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('brand_id')->constrained()->cascadeOnDelete();

            // Same-module reference, real FK allowed (ADR-0014).
            $table->foreignUlid('order_id')->constrained()->restrictOnDelete();

            $table->string('invoice_number');
            $table->date('issued_on');
            $table->date('due_on');

            $table->string('currency', 3);
            $table->decimal('total_net', 10, 2);
            $table->decimal('total_tax', 10, 2);
            $table->decimal('total_gross', 10, 2);
            $table->string('tax_treatment');
            $table->string('price_display');

            $table->json('billing_party');
            $table->json('seller');

            // The rendered, archived ZUGFeRD document (ADR-0031): written
            // once by the generation job, hash-sealed. Nullable only between
            // issuance and the job completing.
            $table->string('document_path')->nullable();
            $table->string('document_sha256', 64)->nullable();
            $table->timestamp('document_generated_at')->nullable();

            $table->timestamps();

            $table->unique(['brand_id', 'invoice_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('invoice_number_ranges');
    }
};
