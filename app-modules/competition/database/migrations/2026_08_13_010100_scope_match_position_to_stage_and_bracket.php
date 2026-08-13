<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A match's position is unique within its *bracket*, not within the whole
     * competition.
     *
     * 10a constrained `(competition_id, round, position)`, which was right
     * while a competition was a single flat list of matches. It stops being
     * right the moment stages exist: a double-elimination stage has a winners
     * round 1 position 1 *and* a losers round 1 position 1, and a group stage
     * has one per group. The old index rejected both — a bug 10a could not
     * have shown, because neither shape could be built yet.
     *
     * `COALESCE` rather than a plain composite index: Postgres treats NULLs as
     * distinct, so a plain unique over nullable `stage_id`/`bracket` would
     * quietly stop constraining the stage-less matches 10a created — trading
     * one wrong constraint for no constraint.
     */
    public function up(): void
    {
        Schema::table('competition_matches', function (Blueprint $table): void {
            $table->dropUnique(['competition_id', 'round', 'position']);
        });

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX competition_matches_bracket_position_unique
            ON competition_matches (
                competition_id,
                COALESCE(stage_id::text, ''),
                COALESCE(bracket, ''),
                round,
                position
            )
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS competition_matches_bracket_position_unique');

        Schema::table('competition_matches', function (Blueprint $table): void {
            $table->unique(['competition_id', 'round', 'position']);
        });
    }
};
