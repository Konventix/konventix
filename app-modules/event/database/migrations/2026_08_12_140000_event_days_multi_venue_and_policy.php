<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * The Round 6 additions to Event (docs/inventory/decision-log.md):
     *
     * - D-EVT-15 (user override): MULTI-venue at release — `event_venues`
     *   M:N with a primary flag replaces the nullable `events.venue_id`.
     *   Zero venues (online-only) is the empty-list case.
     * - D-ADM-04 (user override): first-class event days — Admission
     *   validates against a specific day, not just a from/until pair.
     * - D-ADM-03: the re-entry policy knob lives on the event
     *   (once_only / once_per_day / unlimited, default unlimited).
     * - D-EVT-14 (user override): `predecessor_event_id` from the start —
     *   plain ULID, no FK (the decision names ADR-0014's shape even
     *   within the module, since a predecessor may be archived/pruned
     *   independently).
     */
    public function up(): void
    {
        Schema::create('event_days', function (Blueprint $table): void {
            // ULID primary key — ADR-0014.
            $table->ulid('id')->primary();

            // Brand-owned — ADR-0010.
            $table->foreignUlid('brand_id')->constrained()->cascadeOnDelete();

            // Same-module reference, real FK allowed (ADR-0014).
            $table->foreignUlid('event_id')->constrained()->cascadeOnDelete();

            $table->date('date');
            $table->unsignedSmallInteger('sequence');
            $table->string('label')->nullable();

            $table->timestamps();

            $table->unique(['event_id', 'date']);
            $table->unique(['event_id', 'sequence']);
        });

        Schema::create('event_venues', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('brand_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('event_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('venue_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_primary')->default(false);

            $table->timestamps();

            $table->unique(['event_id', 'venue_id']);
        });

        // Carry existing single-venue assignments into the pivot as primary.
        foreach (DB::table('events')->whereNotNull('venue_id')->get(['id', 'brand_id', 'venue_id']) as $event) {
            DB::table('event_venues')->insert([
                'id' => (string) Str::ulid(),
                'brand_id' => $event->brand_id,
                'event_id' => $event->id,
                'venue_id' => $event->venue_id,
                'is_primary' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Schema::table('events', function (Blueprint $table): void {
            $table->dropColumn('venue_id');
            $table->ulid('predecessor_event_id')->nullable();
            $table->string('reentry_policy')->default('unlimited');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            $table->dropColumn(['predecessor_event_id', 'reentry_policy']);
            $table->foreignUlid('venue_id')->nullable()->constrained('venues')->nullOnDelete();
        });

        Schema::dropIfExists('event_venues');
        Schema::dropIfExists('event_days');
    }
};
