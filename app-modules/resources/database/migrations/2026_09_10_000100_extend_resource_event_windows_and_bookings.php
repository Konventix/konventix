<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Seating S0 (design doc §4.3, §4.7).
     *
     * `resource_event_windows` learns the event's name and slug, so the plan
     * console can name events without reading Event's models (ADR-0004).
     *
     * `resource_bookings` learns which registration *amendment* produced a
     * booking — the idempotency key with `registration_id`, exactly the
     * shape Admission uses on `admissions.amendment_id` — which seat claim
     * it fulfils, and a snapshot of the resource label at booking time so
     * history rows read like the plan did when the booking happened.
     *
     * `claim_id` is a plain ULID here, because S0 already writes it
     * (`HoldResourceBooking`) while `resource_claims` does not exist yet.
     * The table arrives in slice S1, whose migration
     * `2026_09_10_000250_add_claim_id_to_resource_bookings_table.php`
     * attaches the foreign key (`nullOnDelete`) and the `(claim_id, state)`
     * index to this column — it must not add the column a second time.
     */
    public function up(): void
    {
        Schema::table('resource_event_windows', function (Blueprint $table): void {
            $table->string('name')->nullable()->after('event_id');
            $table->string('slug')->nullable()->after('name');
        });

        Schema::table('resource_bookings', function (Blueprint $table): void {
            $table->ulid('claim_id')->nullable()->after('registration_id');
            $table->ulid('amendment_id')->nullable()->after('claim_id');
            $table->string('label')->nullable()->after('quantity');

            $table->index(['registration_id', 'amendment_id']);
        });
    }

    public function down(): void
    {
        Schema::table('resource_bookings', function (Blueprint $table): void {
            $table->dropIndex(['registration_id', 'amendment_id']);
            $table->dropColumn(['claim_id', 'amendment_id', 'label']);
        });

        Schema::table('resource_event_windows', function (Blueprint $table): void {
            $table->dropColumn(['name', 'slug']);
        });
    }
};
