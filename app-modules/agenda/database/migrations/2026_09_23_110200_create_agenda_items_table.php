<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One scheduled programme entry (design §4.2): a title, an optional
     * description, a place, and a time that may be open at either end.
     */
    public function up(): void
    {
        Schema::create('agenda_items', function (Blueprint $table): void {
            // ULID primary key — ADR-0014.
            $table->ulid('id')->primary();

            // Brand-owned — ADR-0010.
            $table->foreignUlid('brand_id')->constrained()->cascadeOnDelete();

            // Event is another module — plain ULID, no FK (ADR-0014).
            $table->ulid('event_id');

            // Same module — real FKs, and a vanished track leaves the item placeless
            // rather than deleting a programme entry somebody published.
            $table->foreignUlid('track_id')->nullable()->constrained('agenda_tracks')->nullOnDelete();
            $table->foreignUlid('location_id')->nullable()->constrained('agenda_locations')->nullOnDelete();

            $table->string('title');
            $table->text('description')->nullable();
            $table->timestampTz('starts_at')->nullable();
            $table->timestampTz('ends_at')->nullable();

            // The one-off place that is not worth a row of its own
            // ("Parkplatz", "vor der Halle").
            $table->string('location_note')->nullable();

            // A plain value: an external speaker needs no account.
            $table->string('speaker')->nullable();

            $table->string('state', 16)->default('draft');
            $table->string('cancelled_reason')->nullable();
            $table->timestamps();

            $table->index(['event_id', 'starts_at']);
        });

        // D-AGD-04: at least one of the two, enforced in the database so no writer
        // can produce an item the grid cannot place.
        DB::statement(<<<'SQL'
            ALTER TABLE agenda_items
            ADD CONSTRAINT agenda_items_has_a_time
            CHECK (starts_at IS NOT NULL OR ends_at IS NOT NULL)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('agenda_items');
    }
};
