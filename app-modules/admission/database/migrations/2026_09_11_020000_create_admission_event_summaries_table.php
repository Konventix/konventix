<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Admission's read model of an event's name, schedule and primary
     * venue (design doc §13, 2026-09-11 amendment "Admission learns event
     * facts"): the ticket's event band (§4.2) needs a name, a local start
     * and end, a timezone and the venue's address, and Admission may not
     * read Event's models (ADR-0004) — so it keeps its own copy, projected
     * from Event's contract events, the way Resources keeps
     * `resource_event_windows`.
     *
     * Sits beside `entrance_event_profiles`, which holds only the day
     * calendar and the re-entry policy for the door (ADR-0033) — a
     * different concern, not extended for this.
     *
     * Every column but the identity pair (`brand_id`, `event_id`) is
     * nullable: a row's fields arrive on different contract events over
     * time (`EventCreated` now; later `EventDetailsUpdated`/
     * `EventRescheduled`/`VenueAssigned`), so the table must tolerate
     * partial knowledge at any point — same reasoning as
     * `EntranceEventProfile`'s nullable `poll_seconds`. `venue_id` is
     * bookkeeping, not itself printed on the ticket or the calendar: it is
     * what lets `Listeners\ProjectEventSummary::handleVenueUpdated()` find
     * this row again when the venue is renamed, and lets
     * `handleVenueUnassigned()` tell its own primary venue apart from any
     * other.
     */
    public function up(): void
    {
        Schema::create('admission_event_summaries', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('brand_id')->constrained()->cascadeOnDelete();

            // Event is a different module — plain ULID, no FK (ADR-0014).
            $table->ulid('event_id');

            $table->string('name')->nullable();
            $table->string('slug')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->string('timezone')->nullable();
            $table->string('venue_label')->nullable();
            $table->ulid('venue_id')->nullable();

            $table->timestamps();

            $table->unique(['brand_id', 'event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admission_event_summaries');
    }
};
