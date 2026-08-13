<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Admission's read model of the two Event facts the door needs to decide
     * offline (ADR-0033): the event-day calendar and the re-entry policy.
     *
     * A read model rather than a join, because Admission may not read Event's
     * models (ADR-0004) — it is projected from `EventDaysChanged` and
     * `EventReentryPolicyChanged`, both of which carry their complete current
     * value so a replay converges instead of drifting.
     *
     * Keyed by `event_id` because there is exactly one profile per event, and
     * that is also how the entrance endpoints look it up.
     */
    public function up(): void
    {
        Schema::create('entrance_event_profiles', function (Blueprint $table): void {
            // ULID primary key — ADR-0014.
            $table->ulid('id')->primary();

            // Brand-owned — ADR-0010.
            $table->foreignUlid('brand_id')->constrained()->cascadeOnDelete();

            // Event is a different module — plain ULID, no FK (ADR-0014).
            $table->ulid('event_id');

            // Unlimited is D-ADM-03's default, and the safe reading if the
            // policy projection has not arrived yet.
            $table->string('reentry_policy')->default('unlimited');

            // [{day_id, date, sequence, label}] — the full current set, as
            // EventDaysChanged delivers it.
            $table->json('days')->nullable();

            // ADR-0033 requires a defined sync cadence and staleness
            // threshold but deliberately sets no numbers. Defaults live on
            // EntranceEventProfile; these columns let a big event tighten the
            // window without re-deploying every scanner. Null means "use the
            // default".
            $table->unsignedSmallInteger('poll_seconds')->nullable();
            $table->unsignedInteger('stale_after_seconds')->nullable();

            $table->timestamps();

            $table->unique(['brand_id', 'event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entrance_event_profiles');
    }
};
