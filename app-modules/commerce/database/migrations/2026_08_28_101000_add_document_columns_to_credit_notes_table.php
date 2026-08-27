<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A Gutschrift gets a document too (ADR-0048 point 2): the same
     * hybrid-ZUGFeRD archive shape `invoices` already carries —
     * `document_path`/`document_disk`/`document_sha256`/`document_generated_at`,
     * written once by `GenerateCreditNoteDocument` and never rewritten
     * (GoBD, ADR-0031 immutability applies to a credit note's document
     * exactly as it does to an invoice's).
     */
    public function up(): void
    {
        Schema::table('credit_notes', function (Blueprint $table): void {
            $table->string('document_path')->nullable();
            $table->string('document_disk', 32)->nullable();
            $table->char('document_sha256', 64)->nullable();
            $table->timestamp('document_generated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('credit_notes', function (Blueprint $table): void {
            $table->dropColumn(['document_path', 'document_disk', 'document_sha256', 'document_generated_at']);
        });
    }
};
