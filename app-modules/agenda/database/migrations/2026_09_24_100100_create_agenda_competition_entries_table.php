<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The programme editor's selection: which of the event's competitions
     * appear in the published programme, under which track and in what
     * order (timetable design §4.5, D-AGD-02). The choice belongs to the
     * person composing the programme; Competition does not know it exists.
     *
     * `competition_id` carries **no FK**, not even to `agenda_competitions`
     * in this module: that read-model row is deleted when the competition is
     * unpublished, and the organizer's selection must survive it, so that
     * republishing brings the block back where the organizer put it.
     */
    public function up(): void
    {
        Schema::create('agenda_competition_entries', function (Blueprint $table): void {
            // ULID primary key — ADR-0014.
            $table->ulid('id')->primary();

            // Brand-owned — ADR-0010.
            $table->foreignUlid('brand_id')->constrained()->cascadeOnDelete();

            // Event and Competition are other modules — plain ULIDs, no FK (ADR-0014).
            $table->ulid('event_id');
            $table->ulid('competition_id');

            // Same module — a real FK, and a vanished track leaves the entry
            // untracked rather than dropping the organizer's selection.
            $table->foreignUlid('track_id')->nullable()->constrained('agenda_tracks')->nullOnDelete();

            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['event_id', 'competition_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agenda_competition_entries');
    }
};
