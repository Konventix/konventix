<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Credit notes and their own gapless number series (ADR-0031, ADR-0036,
     * ADR-0037).
     *
     * An issued invoice is immutable, so a correction is never an edit — it is
     * a credit note against the same order. They serve two flows: refunds
     * (ADR-0037) and post-invoice transfer to a different billing party
     * (ADR-0036), which is why ADR-0036 says credit notes "enter release scope
     * with the refund flow".
     *
     * **A separate series from invoices**, drawn from its own per-(brand,
     * year) range with its own prefix: each sequence stays independently
     * gapless, and a tax advisor can tell a credit note from an invoice by the
     * number alone. `credit_note_number_ranges` deliberately mirrors
     * `invoice_number_ranges` rather than adding a discriminator to it, so
     * neither counter can perturb the other.
     *
     * Like an invoice, a credit note snapshots its parties and amounts: it
     * must still read correctly years later when the brand, the billing party
     * and the tax rates have all moved on.
     */
    public function up(): void
    {
        Schema::create('credit_note_number_ranges', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('brand_id')->constrained()->cascadeOnDelete();

            $table->string('prefix', 16);
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('counter')->default(0);

            $table->timestamps();

            $table->unique(['brand_id', 'year']);
        });

        Schema::create('credit_notes', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('brand_id')->constrained()->cascadeOnDelete();

            // Same module — real FKs allowed (ADR-0014).
            $table->foreignUlid('order_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('invoice_id')->nullable()->constrained()->nullOnDelete();

            $table->string('credit_note_number');

            // Snapshots, not lookups — the document must read correctly long
            // after the underlying rows have changed (ADR-0031).
            $table->json('billing_party');
            $table->json('seller');

            $table->decimal('total_net', 10, 2);
            $table->decimal('total_tax', 10, 2);
            $table->decimal('total_gross', 10, 2);
            $table->string('tax_treatment');
            $table->string('currency', 3);

            $table->string('reason_code');
            $table->text('reason_note')->nullable();

            $table->timestamp('issued_on');

            $table->timestamps();

            $table->unique(['brand_id', 'credit_note_number']);
            $table->index(['brand_id', 'order_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_notes');
        Schema::dropIfExists('credit_note_number_ranges');
    }
};
