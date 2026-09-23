<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The event's IANA timezone on Competition's copy of it (design §10a
 * "Time", ADR-0027): a match time is an instant, and the hall reads it on
 * the event's wall clock. Projected from `EventCreated` and
 * `EventRescheduled`, both of which carry it; `event:replay-details`
 * re-emits `EventRescheduled` for every event, which backfills a row
 * written before this column existed.
 *
 * Null means "nobody has told us yet", and the pages then fall back to the
 * reader's own zone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('competition_event_summaries', function (Blueprint $table): void {
            $table->string('timezone', 64)->nullable()->after('state');
        });
    }

    public function down(): void
    {
        Schema::table('competition_event_summaries', function (Blueprint $table): void {
            $table->dropColumn('timezone');
        });
    }
};
