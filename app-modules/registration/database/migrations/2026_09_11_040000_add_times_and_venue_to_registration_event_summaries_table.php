<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `registration_event_summaries` gains an event's schedule and primary
     * venue (design doc §13, 2026-09-11 amendment "the calendar builder
     * moves to Platform, and two modules gain an event read model").
     * Registration builds the confirmation mail's calendar attachment
     * through `Konventix\Platform\Calendar\CalendarFile` — scalars in,
     * bytes out — and may not read Event's models to supply them
     * (ADR-0004), so this table, already Registration's own copy of an
     * event's name and slug, grows the rest of what that call needs.
     *
     * All four display columns are nullable: existing rows have none of
     * them until `event:replay-details` backfills them. `venue_id` is
     * bookkeeping, not itself printed anywhere: it is what lets
     * `Listeners\ProjectEventSummary::handleVenueUpdated()` find this row
     * again when the venue is renamed, and `handleVenueUnassigned()` tell
     * its own primary venue apart from any other.
     */
    public function up(): void
    {
        Schema::table('registration_event_summaries', function (Blueprint $table): void {
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->string('timezone')->nullable();
            $table->string('venue_label')->nullable();
            $table->ulid('venue_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('registration_event_summaries', function (Blueprint $table): void {
            $table->dropColumn(['starts_at', 'ends_at', 'timezone', 'venue_label', 'venue_id']);
        });
    }
};
