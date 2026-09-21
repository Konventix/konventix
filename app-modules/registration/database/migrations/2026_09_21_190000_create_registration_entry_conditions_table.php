<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Task 30 / D-REG-17 (spec §4.1): the condition a reduced fare rests on
     * — "student", "apprentice", "member" — written once per brand and
     * reused across events and types.
     *
     * `event_id` is nullable and null is the normal case: a condition is
     * brand-wide and reusable, and a value pins a one-off condition to a
     * single event. It is Event's ULID, so no FK (ADR-0014).
     *
     * `key` rather than the ULID is what travels to the door and into log
     * lines; the ULID is what a Compliance `policy_bindings.target_key`
     * points at. Both are load-bearing outside this module, which is why the
     * key is unique per brand and stable.
     *
     * `assertion_text` is the sentence the registrant ticks, verbatim. It may
     * be reworded later — the evidence is the snapshot on the assertion row,
     * never this column (T-6).
     *
     * Archived, never deleted: `archived_at` takes a condition out of the
     * type editor while every assertion that named it stays readable
     * (D-REG-19, spec §4.2).
     */
    public function up(): void
    {
        Schema::create('registration_entry_conditions', function (Blueprint $table): void {
            // ULID primary key — ADR-0014.
            $table->ulid('id')->primary();

            // Brand-owned — ADR-0010. `BelongsToBrand` stamps this.
            $table->foreignUlid('brand_id')->constrained()->cascadeOnDelete();

            // Event is a different module — plain ULID, no FK (ADR-0014).
            $table->ulid('event_id')->nullable()->index();

            $table->string('key', 40);
            $table->string('name');
            $table->text('description')->nullable();
            $table->text('assertion_text');
            $table->string('document_hint')->nullable();
            $table->string('verification', 32);

            $table->timestamp('archived_at')->nullable();

            $table->timestamps();

            // Stable per brand: the key is what the door and the ticket PDF
            // read, so two conditions of one brand may never share one.
            $table->unique(['brand_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registration_entry_conditions');
    }
};
