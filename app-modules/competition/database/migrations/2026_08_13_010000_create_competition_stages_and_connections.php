<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Stages and the progression graph — the structures ADR-0019's absorbed
     * engine needs.
     *
     * **Stages** exist because a real tournament is rarely one bracket: a
     * group phase feeding a playoff bracket is two stages of different
     * formats over the same entrants. LanBrackets modelled this as
     * `CompetitionStage` and the inventory's rename target keeps it. 10a
     * hung matches directly off the competition, which is the degenerate
     * one-stage case; that shape does not survive contact with group stages.
     *
     * **`match_connections`** is the progression graph: an explicit edge
     * saying "the winner of match A becomes slot 2 of match B". Explicit
     * edges rather than positional arithmetic (`floor((position-1)/2)+1`)
     * because double elimination's losers bracket cannot be expressed
     * arithmetically — a loser drops into a match determined by a crossover
     * rule, not by its own position — and one mechanism that handles every
     * format beats two that disagree at the edges.
     */
    public function up(): void
    {
        Schema::create('competition_stages', function (Blueprint $table): void {
            // ULID primary key — ADR-0014.
            $table->ulid('id')->primary();

            // Brand-owned — ADR-0010.
            $table->foreignUlid('brand_id')->constrained()->cascadeOnDelete();

            // Same module — real FK allowed (ADR-0014).
            $table->foreignUlid('competition_id')->constrained()->cascadeOnDelete();

            $table->string('name');

            // Which format engine runs this stage. A plain string so
            // registering a sixth format needs no migration.
            $table->string('format', 32);

            // 1-based running order: groups then playoffs.
            $table->unsignedSmallInteger('sequence')->default(1);

            // The format's ruleset — best_of, points_win/draw/loss,
            // allow_draws, group_size, grand_final_reset, tiebreaker. A JSON
            // column behind a typed cast (ADR-0009); each format validates
            // its own keys.
            $table->json('settings')->nullable();

            $table->timestamp('generated_at')->nullable();

            $table->timestamps();

            $table->unique(['competition_id', 'sequence']);
        });

        Schema::table('competition_matches', function (Blueprint $table): void {
            // Nullable so 10a's matches survive: a match with no stage is the
            // hand-made kind that predates the engine.
            $table->foreignUlid('stage_id')->nullable()->after('competition_id')
                ->constrained('competition_stages')->cascadeOnDelete();

            // Which half of a double-elimination bracket this match sits in,
            // and null for every format that has no such notion.
            $table->string('bracket', 16)->nullable()->after('round');

            $table->index(['stage_id', 'round']);
        });

        Schema::create('match_connections', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('brand_id')->constrained()->cascadeOnDelete();

            $table->foreignUlid('source_match_id')->constrained('competition_matches')->cascadeOnDelete();
            $table->foreignUlid('target_match_id')->constrained('competition_matches')->cascadeOnDelete();

            // 'winner' | 'loser' — which side of the source match travels
            // this edge. Losers only ever travel in double elimination.
            $table->string('source_outcome', 16);

            // Which slot of the target match they land in.
            $table->unsignedSmallInteger('target_slot');

            $table->timestamps();

            // One edge per (source, outcome): a winner goes exactly one place.
            $table->unique(['source_match_id', 'source_outcome']);
            $table->index('target_match_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('match_connections');

        Schema::table('competition_matches', function (Blueprint $table): void {
            $table->dropIndex(['stage_id', 'round']);
            $table->dropConstrainedForeignId('stage_id');
            $table->dropColumn('bracket');
        });

        Schema::dropIfExists('competition_stages');
    }
};
