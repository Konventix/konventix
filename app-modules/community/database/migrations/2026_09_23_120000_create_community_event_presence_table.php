<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The admission badge (design §4.5, ADR-0067 decision 2): the only
     * presence fact Community writes to disk, projected from Admission's
     * public participant events (ADR-0004 — Community may not read
     * `admission_event_presence`). It is a badge sourced from Admission, not
     * a record of who was at their keyboard — the connection axis stays in
     * Redis, TTL'd, never persisted (design §5).
     *
     * `event_id`/`user_id` name Admission's event and Identity's account —
     * plain ULIDs, no FK (ADR-0014). Only `updated_at` is kept, not the
     * usual pair: the row is a current badge, not a history, and "when was
     * this last touched" is the only clock the design asks for (§4.5 lists
     * `updated_at` alone, unlike every other table in §4).
     */
    public function up(): void
    {
        Schema::create('community_event_presence', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('brand_id')->constrained()->cascadeOnDelete();

            // Admission's event and Identity's account — plain ULIDs, no FK
            // (ADR-0014).
            $table->ulid('event_id');
            $table->ulid('user_id');

            $table->boolean('checked_in')->default(false);
            $table->string('last_direction', 3)->nullable();

            $table->timestampTz('updated_at');

            $table->unique(['event_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('community_event_presence');
    }
};
