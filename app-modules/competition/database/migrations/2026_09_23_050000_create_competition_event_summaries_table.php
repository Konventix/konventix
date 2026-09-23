<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An event as Competition knows it (design §10, ADR-0004): the `slug` the
 * public tournament pages resolve `/events/{slug}/tournaments` by, and the
 * `name` they are headed with. Competition may not read Event's models, so
 * both are projected from Event's contract events by
 * `Listeners\ProjectEventSummary` — the shape Agenda and Admission already
 * keep for the same reason.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('competition_event_summaries', function (Blueprint $table): void {
            // ULID primary key — ADR-0014.
            $table->ulid('id')->primary();

            // Brand-owned — ADR-0010.
            $table->foreignUlid('brand_id')->constrained()->cascadeOnDelete();

            // Event is another module — plain ULID, no FK (ADR-0014).
            $table->ulid('event_id');

            $table->string('slug')->nullable();
            $table->string('name')->nullable();

            $table->timestamps();

            $table->unique(['brand_id', 'event_id']);
            $table->index(['brand_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('competition_event_summaries');
    }
};
