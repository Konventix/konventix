<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The locale an invoice or credit note was issued in (ADR-0068 §6). A
     * document is a record: every re-render reads this column, never the
     * worker's or the reader's current locale.
     *
     * The default is `de` because every row that exists before this column
     * was rendered German — the templates had no other language then. New
     * rows always set it explicitly (`IssueInvoice`, `IssueCreditNote`), so
     * the default only ever speaks for the past.
     */
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->string('locale', 12)->default('de');
        });

        Schema::table('credit_notes', function (Blueprint $table): void {
            $table->string('locale', 12)->default('de');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropColumn('locale');
        });

        Schema::table('credit_notes', function (Blueprint $table): void {
            $table->dropColumn('locale');
        });
    }
};
