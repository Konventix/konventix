<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The leaderboard format and the multi-stage model (D-CMP-16, design
     * §7.2, §7.3).
     *
     * **Two columns on `competition_stages`.** A real tournament is rarely
     * one bracket — a league before a double-elimination final, qualifiers
     * into a final — and entrants have to flow between the stages. §7.3
     * settles that model *now* so the single-stage build does not have to be
     * undone: `entry_source` says where a stage's field comes from and
     * `advance_count` says how many of its entrants the next stage takes.
     * Only `signup` has a seed source in this slice; `previous_stage` is
     * modelled and refused by name.
     *
     * `advance_count` is "how many the **next** stage takes from this one",
     * not "how many this stage takes". It is written on the stage that people
     * advance *out of*, because that is the stage whose standings decide it;
     * a group stage reads it per group (§7.3's `advance_per_group`), which
     * lives in that format's own `settings` rather than in a second column
     * nothing else would ever set.
     *
     * **`competition_score_entries`** is the whole of the leaderboard format.
     * A stage with no matches, where each entrant accumulates values — a lap
     * time, points in a heat, one juror's score — and the standings rank the
     * aggregate `LeaderboardSettings` names. It covers all four of SXLAN's
     * non-paired tournaments with one table; free-for-all heats as matches
     * with N slots would be the richer model and is deliberately not built
     * (§7.2).
     *
     * `verified_at` nullable is the point of the table: an entry does not
     * count until an organizer verifies it (§8.3), and "somebody typed a
     * number" and "the number is in the table" are different facts.
     *
     * `proof_media_id`, `submitted_by_user_id` and `verified_by_user_id`
     * carry no foreign keys — Platform owns the media library and Identity
     * owns users, and a cross-module FK is what ADR-0014 forbids. `brand_id`,
     * `stage_id` and `entrant_id` do: Platform is the scoping level rather
     * than a module boundary, and the other two are this module's own tables.
     *
     * The unique index is written by hand as `UNIQUE NULLS NOT DISTINCT`
     * (Postgres 15+, the same reason `policies` needed it): `heat` is null
     * for a stage without heats, and Postgres treats NULLs as distinct, so a
     * plain unique would let one entrant submit a Trackmania time as often as
     * they liked and have every copy counted.
     */
    public function up(): void
    {
        Schema::table('competition_stages', function (Blueprint $table): void {
            $table->string('entry_source', 24)->default('signup')->after('sequence');
            $table->unsignedSmallInteger('advance_count')->nullable()->after('entry_source');
        });

        Schema::create('competition_score_entries', function (Blueprint $table): void {
            // ULID primary key — ADR-0014.
            $table->ulid('id')->primary();

            // Brand-owned — ADR-0010.
            $table->foreignUlid('brand_id')->constrained()->cascadeOnDelete();

            // Same module — real FKs allowed (ADR-0014).
            $table->foreignUlid('stage_id')->constrained('competition_stages')->cascadeOnDelete();
            $table->foreignUlid('entrant_id')->constrained('entrants')->cascadeOnDelete();

            // 1..n when the stage has heats, null when it has one entry per
            // entrant. Not a default of 1: the two shapes are different
            // questions (see `LeaderboardSettings::acceptsHeat()`).
            $table->unsignedSmallInteger('heat')->nullable();

            // A time in seconds, a point total, a jury score.
            $table->decimal('value', 12, 3);

            // Platform media (ADR-0035), when the competition's proof policy
            // asks for one.
            $table->ulid('proof_media_id')->nullable();

            $table->ulid('submitted_by_user_id');

            $table->timestamp('verified_at')->nullable();
            $table->ulid('verified_by_user_id')->nullable();

            $table->string('note')->nullable();

            $table->timestamps();

            // The two reads: the calculator wants a stage's verified rows,
            // and the desk's verification queue wants the unverified ones.
            $table->index(['stage_id', 'verified_at']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE competition_score_entries
            ADD CONSTRAINT competition_score_entries_stage_entrant_heat_unique
            UNIQUE NULLS NOT DISTINCT (stage_id, entrant_id, heat)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('competition_score_entries');

        Schema::table('competition_stages', function (Blueprint $table): void {
            $table->dropColumn(['entry_source', 'advance_count']);
        });
    }
};
