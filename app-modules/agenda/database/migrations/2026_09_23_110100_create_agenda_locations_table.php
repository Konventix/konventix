<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Agenda owns its own places — "Halle 3", "Bühne", "Foyer" — because the
     * platform has no room entity to borrow (design §4.3). Resources models
     * one bookable *seat* per row (a 40-seat room is 40 resources) and
     * Event's `Venue` is a building with an address, not a hall inside one.
     *
     * `venue_id` optionally names the Event venue a place sits in, by ULID
     * and without a foreign key (ADR-0014); it is display context and
     * nothing depends on it. If a room ever becomes a first-class thing
     * elsewhere, this table is the seam that adopts it.
     */
    public function up(): void
    {
        Schema::create('agenda_locations', function (Blueprint $table): void {
            // ULID primary key — ADR-0014.
            $table->ulid('id')->primary();

            // Brand-owned — ADR-0010.
            $table->foreignUlid('brand_id')->constrained()->cascadeOnDelete();

            // Event is another module — plain ULID, no FK (ADR-0014).
            $table->ulid('event_id');

            // Event's venue, likewise by ULID only.
            $table->ulid('venue_id')->nullable();

            $table->string('name');
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['event_id', 'name']);
            $table->index(['brand_id', 'event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agenda_locations');
    }
};
