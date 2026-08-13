<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Dunning state on the invoice.
     *
     * These are the only columns written to an invoice after issuance besides
     * the document fields — and they are deliberately *about* the invoice
     * rather than *of* it. ADR-0031's immutability covers what the document
     * says: its number, parties, amounts and tax. How many reminders have been
     * sent is not part of that statement and does not alter it.
     *
     * `overdue_at` doubles as the once-only marker for `InvoiceOverdue`: the
     * sweep runs daily, and re-emitting would turn one unpaid invoice into a
     * daily notification about the same fact.
     */
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->timestamp('overdue_at')->nullable()->after('due_on');
            $table->unsignedSmallInteger('dunning_level')->default(0)->after('overdue_at');
            $table->timestamp('dunning_raised_at')->nullable()->after('dunning_level');

            // The sweep's working set: unpaid, issued, past due.
            $table->index(['brand_id', 'due_on']);
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropIndex(['brand_id', 'due_on']);
            $table->dropColumn(['overdue_at', 'dunning_level', 'dunning_raised_at']);
        });
    }
};
