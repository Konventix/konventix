<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Resources' read model of when an event runs.
     *
     * Needed because ADR-0020 requires every booking to carry an interval,
     * and a resource claim from a registration's grants is a whole-event
     * booking — so fulfilling one means knowing the event's start and end.
     * Resources may not read Event's models (ADR-0004), so it keeps its own
     * copy, projected from `EventCreated` and `EventRescheduled`.
     *
     * `EventRescheduled` is the reason this is a projection rather than a
     * value copied once: ADR-0020 assigns Resources the job of shifting
     * whole-event bookings when an event moves, which it cannot do without
     * knowing the event moved.
     */
    public function up(): void
    {
        Schema::create('resource_event_windows', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('brand_id')->constrained()->cascadeOnDelete();

            // Event is a different module — plain ULID, no FK (ADR-0014).
            $table->ulid('event_id');

            $table->timestamp('starts_at');
            $table->timestamp('ends_at');

            $table->timestamps();

            $table->unique(['brand_id', 'event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resource_event_windows');
    }
};
