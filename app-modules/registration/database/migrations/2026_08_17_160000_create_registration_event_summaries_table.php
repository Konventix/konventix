<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Registration's event read model (ADR-0004). Registration may not read
     * Event's tables, but every registration type belongs to an event, and an
     * organizer choosing one should pick a name from a list rather than paste
     * a ULID.
     *
     * Name and slug only, plus whether the event has been archived — enough
     * to label and to stop offering it. Dates deliberately stay out: nothing
     * in Registration schedules against them, and a projection that copies
     * fields it does not use is a second source of truth waiting to drift.
     */
    public function up(): void
    {
        Schema::create('registration_event_summaries', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('brand_id')->constrained()->cascadeOnDelete();

            // Event is a different module — plain ULID, no FK (ADR-0014).
            $table->ulid('event_id');
            $table->string('name');
            $table->string('slug');
            $table->timestamp('archived_at')->nullable();

            $table->timestamps();

            $table->unique(['brand_id', 'event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registration_event_summaries');
    }
};
