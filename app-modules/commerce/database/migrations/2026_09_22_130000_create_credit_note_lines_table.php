<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What a credit note credits, line by line (#7, D-0.8.2-1).
     *
     * Until now a credit note carried three totals and nothing else, so a
     * partial one could only ever inherit the invoice's tax allocation —
     * which is wrong the moment an invoice mixes rates, and wrong in a way
     * a bookkeeper has to unpick by hand. A partial credit note is now
     * expressed as *lines and quantities*, each computed at the order
     * line's own snapshotted rate (ADR-0017), and these rows are that
     * computation, snapshotted for the same reason `order_lines` and the
     * invoice totals are: the document must re-render identically years
     * later (ADR-0031).
     *
     * **They are also the credited-quantity ledger.** #7's third acceptance
     * criterion — the sum of an invoice's credit notes never exceeds its
     * gross — is necessary but not sufficient once lines exist: an invoice
     * with a 200.00 line and a 100.00 line has room for a second 100.00
     * credit note at the total level while the *line* it names has already
     * been credited in full. `sum(quantity) per order_line_id` is what
     * makes that refusable, which is why `order_line_id` is indexed and
     * carried even though every other column here is a snapshot.
     *
     * Same module, so real foreign keys are allowed (ADR-0014); both
     * cascade, because a credit note without its lines would render a
     * document that contradicts its own totals.
     *
     * A credit note issued before this table existed has no rows here, and
     * that is a state rather than a gap: it credits an invoice as a whole
     * and the document renders the summary position it always did.
     */
    public function up(): void
    {
        Schema::create('credit_note_lines', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('brand_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('credit_note_id')->constrained()->cascadeOnDelete();
            // The invoice line this credits — an `order_lines` row, because
            // an invoice has no lines of its own: `InvoiceDocumentRenderer`
            // renders the order's (ADR-0031).
            $table->foreignUlid('order_line_id')->constrained('order_lines')->cascadeOnDelete();

            $table->unsignedInteger('position');
            $table->string('label');

            // Quantities, never fractions of one: see `IssueCreditNote`.
            $table->unsignedInteger('quantity');

            // The order line's settlement-time snapshot, copied rather than
            // read live — an order credited next year is credited at the
            // rate it was charged at (ADR-0017).
            $table->decimal('unit_amount_net', 10, 2);
            $table->string('tax_class');
            $table->decimal('tax_rate', 5, 2);

            $table->decimal('line_net', 10, 2);
            $table->decimal('line_tax', 10, 2);
            $table->decimal('line_gross', 10, 2);

            $table->timestamps();

            $table->index(['brand_id', 'credit_note_id']);
            // The ledger read: "how much of this line is already credited?"
            $table->index(['brand_id', 'order_line_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_note_lines');
    }
};
