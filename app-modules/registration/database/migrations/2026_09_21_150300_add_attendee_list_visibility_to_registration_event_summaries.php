<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Registration's copy of the per-event attendee-list switch (ADR-0064 §6),
 * projected from `EventAttendeeListPolicyChanged` and held as that enum's
 * scalar value (`off`/`members`/`public`) because this module may not
 * import `Konventix\Event\Enums\AttendeeListVisibility` (ADR-0004).
 *
 * Backfilled once from `events`, the same one-off shape `2026_09_08_970000`
 * used for `visibility`: a migration is deployment infrastructure, this is
 * one of the two places Registration reads `events`, and every later
 * change arrives through the contract event. Every existing event defaults
 * to `off` there, so the backfill is a formality that keeps the two tables
 * from disagreeing on an event whose row was edited between the two
 * deploys.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('registration_event_summaries', function (Blueprint $table): void {
            $table->string('attendee_list_visibility')->default('off');
        });

        DB::statement(<<<'SQL'
            UPDATE registration_event_summaries AS s
            SET attendee_list_visibility = e.attendee_list_visibility
            FROM events AS e
            WHERE e.id = s.event_id
        SQL);
    }

    public function down(): void
    {
        Schema::table('registration_event_summaries', function (Blueprint $table): void {
            $table->dropColumn('attendee_list_visibility');
        });
    }
};
