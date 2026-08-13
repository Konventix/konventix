<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Standings, persisted rather than computed on every read.
     *
     * The absorbed engine computed standings at query time and stored nothing.
     * That is defensible for a live table and wrong for a finished one: a
     * result correction months later would silently rewrite history, and the
     * ranking a prize was actually awarded on would no longer exist anywhere.
     * The inventory's verdict is "absorb, then persist", and Competition's
     * SPEC lists Standings as owned.
     *
     * It is a projection, not a source of truth — `CalculateStandings`
     * recomputes it wholesale from matches. `computed_at` says when, which is
     * what makes a stale table visible instead of merely wrong.
     */
    public function up(): void
    {
        Schema::create('competition_standings', function (Blueprint $table): void {
            // ULID primary key — ADR-0014.
            $table->ulid('id')->primary();

            // Brand-owned — ADR-0010.
            $table->foreignUlid('brand_id')->constrained()->cascadeOnDelete();

            // Same module — real FKs allowed (ADR-0014).
            $table->foreignUlid('competition_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('stage_id')->constrained('competition_stages')->cascadeOnDelete();
            $table->foreignUlid('entrant_id')->constrained()->cascadeOnDelete();

            // The group this row ranks within, for a group stage. Null means
            // the table is the whole stage.
            $table->string('group', 32)->nullable();

            // Standard competition ranking: tied entrants share a placement,
            // so this is not unique per stage and must not be made so.
            $table->unsignedInteger('placement');

            $table->unsignedInteger('wins')->default(0);
            $table->unsignedInteger('losses')->default(0);
            $table->unsignedInteger('draws')->default(0);
            $table->integer('points')->default(0);

            // Whatever the format ranks on after points: score difference for
            // a league, Buchholz for Swiss. A float because Buchholz is one
            // when the stage allows draws.
            $table->double('tiebreaker')->default(0);

            $table->timestamp('computed_at');

            $table->timestamps();

            // One row per entrant per stage. The recompute upserts against
            // this, which is what makes running it twice a no-op instead of a
            // duplicate table.
            $table->unique(['stage_id', 'entrant_id']);

            // The read: a stage's table in display order.
            $table->index(['stage_id', 'group', 'placement']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('competition_standings');
    }
};
