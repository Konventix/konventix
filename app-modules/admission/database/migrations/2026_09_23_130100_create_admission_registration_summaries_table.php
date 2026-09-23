<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Admission's read model of a registration, as the attendee list and the
     * ticket detail need it (attendee-list design §3, §4.4, #329): the ticket
     * type label and the registration's status. Admission may not read
     * Registration's tables (ADR-0004), so it keeps this copy, projected from
     * `RegistrationConfirmed`, `RegistrationCancelled` and the backfill event
     * `RegistrationStandingAnnounced` by `Listeners\ProjectRegistrationSummary`.
     *
     * One row per registration, hence the unique `registration_id`. The type
     * columns are nullable: a row written from a payload that predates the
     * type name (an older emitter, or a standing announcement without one)
     * still records the status. `status` is Registration's own
     * `RegistrationStatus` value, stored as an opaque string.
     */
    public function up(): void
    {
        Schema::create('admission_registration_summaries', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('brand_id')->constrained()->cascadeOnDelete();

            // Registration is a different module — plain ULIDs, no FK (ADR-0014).
            $table->ulid('registration_id')->unique();
            $table->ulid('registration_type_id')->nullable();

            $table->string('type_name')->nullable();
            $table->string('status', 16);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admission_registration_summaries');
    }
};
