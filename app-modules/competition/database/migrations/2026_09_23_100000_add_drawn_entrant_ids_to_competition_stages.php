<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Who a stage was drawn from (design §4, §7.5; final review, part 1, I4).
 *
 * The bracket is drawn in `SignupClosed`, and several legal moves change the
 * field after that: a withdrawal, a declined incomplete roster, a roster
 * change, a late check-in or an undone one. `StartCompetition` compares the
 * field it would start with against the field the bracket was drawn from and
 * refuses a mismatch, so the desk draws again first.
 *
 * The drawn field is kept rather than read back from the slots because the
 * slots do not hold all of it: a Swiss round one with an odd field leaves its
 * bye entrant out of every match.
 *
 * Backfill: a stage drawn before this column existed gets the entrants its
 * slots hold, which is the whole field for every format but an odd Swiss
 * round one. Such a stage, if its competition has not started yet, is
 * refused at `start` and drawn again, which costs nothing before the first
 * result.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('competition_stages', function (Blueprint $table): void {
            $table->jsonb('drawn_entrant_ids')->nullable()->after('generated_at');
        });

        $stageIds = DB::table('competition_stages')->whereNotNull('generated_at')->pluck('id');

        foreach ($stageIds as $stageId) {
            $entrantIds = DB::table('match_slots')
                ->join('competition_matches', 'competition_matches.id', '=', 'match_slots.competition_match_id')
                ->where('competition_matches.stage_id', $stageId)
                ->whereNotNull('match_slots.entrant_id')
                ->distinct()
                ->orderBy('match_slots.entrant_id')
                ->pluck('match_slots.entrant_id')
                ->all();

            DB::table('competition_stages')
                ->where('id', $stageId)
                ->update(['drawn_entrant_ids' => json_encode(array_values($entrantIds), JSON_THROW_ON_ERROR)]);
        }
    }

    public function down(): void
    {
        Schema::table('competition_stages', function (Blueprint $table): void {
            $table->dropColumn('drawn_entrant_ids');
        });
    }
};
