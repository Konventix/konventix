<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When a badge was printed, and by whom. SPEC.md's "Owns" says Admission
     * owns "what is on it, when it is issued, and when it is reprinted" — a
     * reprint is only distinguishable from a first print if the first one was
     * recorded, so this ledger is what makes `BadgeIssued.reprint` truthful
     * rather than guessed.
     *
     * The rendered PDF itself is deliberately **not** stored. A badge is
     * print-on-demand and reproducible from the admission plus its template,
     * unlike an invoice, which GoBD requires to be archived byte-for-byte
     * (ADR-0031). Keeping attendee-bearing PDFs on disk with no retention
     * rule would be a GDPR liability for no gain.
     */
    public function up(): void
    {
        Schema::create('badge_issuances', function (Blueprint $table): void {
            // ULID primary key — ADR-0014.
            $table->ulid('id')->primary();

            // Brand-owned — ADR-0010.
            $table->foreignUlid('brand_id')->constrained()->cascadeOnDelete();

            // Same module — real FK allowed (ADR-0014).
            $table->foreignUlid('admission_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('badge_template_id')->nullable()->constrained()->nullOnDelete();

            // Identity is a different module — plain ULID, no FK (ADR-0014).
            $table->ulid('issued_by_user_id')->nullable();

            $table->boolean('reprint')->default(false);

            $table->timestamp('issued_at');

            $table->timestamps();

            $table->index(['admission_id', 'issued_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('badge_issuances');
    }
};
