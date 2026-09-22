<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A track is a column of the published grid — "Bühne", "Turniere",
     * "Workshops" (design §4.1). It is an entity rather than a free-text
     * field on the item because a grid needs a stable, ordered column set,
     * and because renaming a track has to rename it everywhere at once.
     */
    public function up(): void
    {
        Schema::create('agenda_tracks', function (Blueprint $table): void {
            // ULID primary key — ADR-0014.
            $table->ulid('id')->primary();

            // Brand-owned — ADR-0010.
            $table->foreignUlid('brand_id')->constrained()->cascadeOnDelete();

            // Event is another module — plain ULID, no FK (ADR-0014).
            $table->ulid('event_id');

            $table->string('name');
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['event_id', 'name']);
            $table->index(['brand_id', 'event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agenda_tracks');
    }
};
