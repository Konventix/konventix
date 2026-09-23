<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Agenda's read model of the event's announced competitions (timetable
     * design §4.5, D-AGD-02, D-AGD-03). Agenda may not read Competition's
     * tables (ADR-0004), so it keeps the four facts a programme block needs
     * — name, slug, start, and whether the block stands or is struck —
     * projected from Competition's contract events by
     * `Listeners\ProjectProgrammeCompetition` and by nothing else.
     *
     * A row exists exactly while the competition is publicly announced:
     * `CompetitionPublished` (or the backfill's `CompetitionAnnounced`)
     * creates it, `CompetitionUnpublished` deletes it. `starts_at` is
     * Competition's and never written by Agenda (D-AGD-03); it is nullable
     * because a published competition may not have a time yet.
     *
     * `competition_id` is unique on its own, not per brand: it is a ULID
     * minted by another module, one competition has one block.
     */
    public function up(): void
    {
        Schema::create('agenda_competitions', function (Blueprint $table): void {
            // ULID primary key — ADR-0014.
            $table->ulid('id')->primary();

            // Brand-owned — ADR-0010.
            $table->foreignUlid('brand_id')->constrained()->cascadeOnDelete();

            // Event and Competition are other modules — plain ULIDs, no FK (ADR-0014).
            $table->ulid('event_id');
            $table->ulid('competition_id')->unique();

            $table->string('name');

            // The public competition page's route key (`competition.page`).
            $table->string('slug')->nullable();

            $table->timestampTz('starts_at')->nullable();

            // `CompetitionBlockState`: published | cancelled.
            $table->string('state', 16);

            $table->timestamps();

            $table->index(['brand_id', 'event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agenda_competitions');
    }
};
