<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What the door has seen, as Competition knows it (D-CMP-18, design §6).
     *
     * A competition may require that every roster member has been checked in
     * **at the event door** before the team may be checked in at the desk.
     * Admission owns that fact and this module may not read its tables
     * (ADR-0004), so the fact is projected here from Admission's public
     * events — `AdmissionHolderAssigned` / `AdmissionHolderUnassigned` say
     * *who* holds an admission, `AdmissionCheckedIn` says it was scanned, and
     * `AdmissionRevoked` clears the row. Same shape Resources built for #292.
     *
     * **This is not Admission's own presence table and must not be confused
     * with it.** Admission grew a presence concept for the live hub, for a
     * different consumer and a different question ("who is inside right
     * now?"). This one answers only "has the door ever seen this person at
     * this event?", and it answers it from the same public events rather than
     * by reaching across the boundary.
     *
     * The key is the admission's own ULID: one row per admission, upserted,
     * so any arrival order converges. `event_id` and `holder_user_id` carry
     * no foreign keys — Event and Identity own those tables, and a cross-
     * module FK is what ADR-0014 forbids. `brand_id` does, because Platform
     * is the scoping level rather than a module boundary.
     *
     * `checked_in_at` nullable is the whole point: a row exists as soon as an
     * admission has a holder, and only a scan in direction `in` fills the
     * timestamp. "Has a row" and "was at the door" are different questions.
     */
    public function up(): void
    {
        Schema::create('competition_admission_presence', function (Blueprint $table): void {
            $table->ulid('admission_id')->primary();

            $table->foreignUlid('brand_id')->constrained()->cascadeOnDelete();

            $table->ulid('event_id');
            $table->ulid('holder_user_id')->nullable();

            $table->timestamp('checked_in_at')->nullable();

            $table->timestamps();

            // The one question the check-in Action asks: for this event, has
            // each of these people been through the door?
            $table->index(['brand_id', 'event_id', 'holder_user_id'], 'competition_presence_holder_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('competition_admission_presence');
    }
};
