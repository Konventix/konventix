<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Disciplines, competitions, teams, entrants and matches.
     *
     * `disciplines` carries duration, eligibility and scoring **locally**
     * (ADR-0024): "duration hints, default eligibility and scoring shape hang
     * off `Discipline` uniformly. There is no null-game case to fall back
     * from, and therefore no null-safe fallback chain to maintain in every
     * consumer." A video-game discipline additionally names a GameOps game by
     * ULID — the only GameOps reference in this module, and nullable, because
     * every other kind has none.
     *
     * Shipped with the GameOps catalogue read model deliberately absent. Its
     * producer does not exist and neither does its contract: GameOps' SPEC
     * lists no catalogue event even as proposed, and disagrees with D-GOP-04
     * about which fields it would carry. ADR-0024 sanctions exactly this
     * staging — "ship `Discipline` with exactly one kind and add the rest
     * later; it is a table and a reference, not a rewrite."
     */
    public function up(): void
    {
        Schema::create('disciplines', function (Blueprint $table): void {
            // ULID primary key — ADR-0014.
            $table->ulid('id')->primary();

            // Brand-owned — ADR-0010.
            $table->foreignUlid('brand_id')->constrained()->cascadeOnDelete();

            $table->string('kind', 24)->default('video_game');
            $table->string('name');
            $table->string('slug');

            // GameOps owns games — plain ULID, no FK, and null for every kind
            // that is not a video game (ADR-0024, ADR-0014).
            $table->ulid('game_id')->nullable();

            // The fields ADR-0024 measured Competition as actually consuming.
            // Held locally rather than read from GameOps at call time: a
            // schedule must still compute with GameOps switched off.
            $table->unsignedSmallInteger('avg_match_minutes')->default(30);
            $table->unsignedSmallInteger('team_size')->default(1);
            $table->boolean('allow_draws')->default(false);

            $table->timestamps();

            $table->unique(['brand_id', 'slug']);
        });

        Schema::create('competitions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('brand_id')->constrained()->cascadeOnDelete();

            // Same module — real FK allowed (ADR-0014).
            $table->foreignUlid('discipline_id')->constrained()->cascadeOnDelete();

            // Event is a different module — plain ULID, no FK (ADR-0014).
            $table->ulid('event_id');

            $table->string('name');
            $table->string('slug');

            // The bracket format this competition will run. A plain string,
            // not an enum column: the format engines arrive with ADR-0019's
            // absorption and register themselves, so adding one must not need
            // a migration.
            $table->string('format', 32)->default('single_elimination');

            $table->timestamp('signup_opens_at')->nullable();
            $table->timestamp('signup_closes_at')->nullable();
            $table->timestamp('published_at')->nullable();

            $table->timestamps();

            $table->unique(['brand_id', 'event_id', 'slug']);
        });

        Schema::create('competition_teams', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('brand_id')->constrained()->cascadeOnDelete();

            // Teams are per event (D-CMP-14): cross-event identity stays
            // Community territory, and `predecessor_event_id` on the event is
            // what a later roster-import UX would lean on.
            $table->ulid('event_id');

            $table->string('name');

            $table->timestamps();

            $table->unique(['brand_id', 'event_id', 'name']);
        });

        Schema::create('competition_team_members', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('brand_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('competition_team_id')->constrained()->cascadeOnDelete();

            // Identity owns users — plain ULID, no FK (ADR-0014).
            $table->ulid('user_id');

            $table->timestamps();

            $table->unique(['competition_team_id', 'user_id']);
        });

        Schema::create('entrants', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('brand_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('competition_id')->constrained()->cascadeOnDelete();

            // Always a team, even for a solo player: D-CMP-13 settles a solo
            // entrant as "a team of size one; the roster UI hides itself for
            // size-1 teams". One shape means the bracket engine never asks
            // whether this is a person or a squad.
            $table->foreignUlid('competition_team_id')->constrained()->cascadeOnDelete();

            $table->string('state', 16)->default('registered');

            // Seeding position, null until seeded.
            $table->unsignedSmallInteger('seed')->nullable();

            $table->timestamp('checked_in_at')->nullable();

            $table->timestamps();

            // Deliberately NOT a plain unique on (competition_id,
            // competition_team_id): that would fire before the partial index
            // below and, worse, would stop a withdrawn team ever re-entering.
            // The partial index is the correct constraint — see below.
            $table->index(['competition_id', 'state']);
        });

        Schema::create('competition_matches', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('brand_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('competition_id')->constrained()->cascadeOnDelete();

            $table->unsignedSmallInteger('round')->default(1);
            $table->unsignedSmallInteger('position')->default(1);

            $table->string('state', 16)->default('pending');

            $table->foreignUlid('winner_entrant_id')->nullable()->constrained('entrants')->nullOnDelete();

            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('finished_at')->nullable();

            // Fired exactly once per match (D-CMP-12), which is what stops
            // GameOps tearing the same server down twice. A correction
            // rewrites the result without touching this.
            $table->timestamp('finished_event_emitted_at')->nullable();

            $table->timestamps();

            $table->unique(['competition_id', 'round', 'position']);
        });

        Schema::create('match_slots', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('brand_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('competition_match_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('entrant_id')->nullable()->constrained()->nullOnDelete();

            // 1 or 2 today; a column rather than two match columns so a format
            // with more than two sides needs no schema change.
            $table->unsignedSmallInteger('position');

            $table->integer('score')->nullable();

            $table->timestamps();

            $table->unique(['competition_match_id', 'position']);
        });

        // Competition's read model of who may enter (D-CMP-05): "confirmed
        // Registration suffices to enter". Built from Registration's contract
        // events, never a query against its tables (ADR-0004, ADR-0006).
        //
        // Two events feed it because neither alone is enough:
        // `RegistrationSubmitted` carries the registrant's user id but not
        // confirmation, and `RegistrationConfirmed` carries confirmation but
        // not the user.
        Schema::create('competition_eligible_registrations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('brand_id')->constrained()->cascadeOnDelete();

            $table->ulid('registration_id');
            $table->ulid('event_id');
            $table->ulid('user_id')->nullable();

            $table->boolean('is_confirmed')->default(false);

            $table->timestamps();

            $table->unique(['brand_id', 'registration_id']);
            $table->index(['brand_id', 'event_id', 'user_id']);
        });

        // One live entrant per team per competition. A double-tap on a signup
        // button must not put a team in the bracket twice, and a read-then-
        // write check loses that race.
        DB::statement(
            'CREATE UNIQUE INDEX entrants_one_live_per_team
             ON entrants (competition_id, competition_team_id)
             WHERE state IN (\'registered\', \'checked_in\')'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('competition_eligible_registrations');
        Schema::dropIfExists('match_slots');
        Schema::dropIfExists('competition_matches');
        Schema::dropIfExists('entrants');
        Schema::dropIfExists('competition_team_members');
        Schema::dropIfExists('competition_teams');
        Schema::dropIfExists('competitions');
        Schema::dropIfExists('disciplines');
    }
};
