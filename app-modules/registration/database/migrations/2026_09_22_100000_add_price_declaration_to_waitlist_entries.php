<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * D-0.8.2-2 (#8): a promoted waitlist registration is priced at the price
     * it was submitted at, not at the price it was promoted at.
     *
     * The queue already snapshots the billing party so a conversion recreates
     * the submission verbatim; it did not snapshot what the submission cost,
     * so a conversion re-read `registration_types.price_declaration` and the
     * registrant paid whatever the type happened to cost weeks later. The
     * same JSON value object as on the type (ADR-0017, ADR-0009), not a
     * second price concept and not three loose columns.
     *
     * Nullable, and nullable for two reasons rather than one: a `free` type
     * has no price at all (never a zero price), and every row queued before
     * this column existed has no snapshot either. Both fall back to the
     * type's current declaration in `RequestSettlement`, which is exactly
     * what happened before this column existed.
     */
    public function up(): void
    {
        Schema::table('waitlist_entries', function (Blueprint $table): void {
            $table->json('price_declaration')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('waitlist_entries', function (Blueprint $table): void {
            $table->dropColumn('price_declaration');
        });
    }
};
