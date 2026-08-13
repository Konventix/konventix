<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Commerce's own read model of which admissions have been checked in
     * (ADR-0037, D-COM-15).
     *
     * The whole point is that Commerce answers "may this order be refunded?"
     * **without ever reading Admission's tables** — it learns from
     * `AdmissionCheckedIn`, whose payload carries `registration_id` for
     * exactly this purpose. That event shipping in Slice 5a is what unblocked
     * this table.
     *
     * Keyed per **admission**, not a counter per registration, for two
     * reasons the ADR names: the projection must converge under replayed or
     * out-of-order events (a counter would drift), and a future
     * partial-refund-of-unused-admissions flow needs to know *which*
     * admissions were used, not how many.
     */
    public function up(): void
    {
        Schema::create('commerce_checked_in_admissions', function (Blueprint $table): void {
            // ULID primary key — ADR-0014.
            $table->ulid('id')->primary();

            // Brand-owned — ADR-0010.
            $table->foreignUlid('brand_id')->constrained()->cascadeOnDelete();

            // Both are foreign entities carried in the event payload; plain
            // ULIDs, no FK (ADR-0014) — Admission owns them.
            $table->ulid('admission_id');
            $table->ulid('registration_id');

            $table->timestamp('first_checked_in_at');

            $table->timestamps();

            // One row per admission: a replayed event updates rather than
            // appends, which is what makes the projection converge.
            $table->unique(['brand_id', 'admission_id']);

            // The refund guard's only question: does this registration have
            // any checked-in admission?
            $table->index(['brand_id', 'registration_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commerce_checked_in_admissions');
    }
};
