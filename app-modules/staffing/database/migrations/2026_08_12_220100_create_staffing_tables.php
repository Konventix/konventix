<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Teams, shift roles, shifts and assignments (D-STF-01, D-STF-02).
     *
     * Teams are **flat** with an area tag, not a tree (D-STF-02): nesting is
     * the feature every roster tool grows and every organizer then works
     * around. A tag answers "which teams are front-of-house" without a
     * hierarchy nobody maintains.
     */
    public function up(): void
    {
        Schema::create('teams', function (Blueprint $table): void {
            // ULID primary key — ADR-0014.
            $table->ulid('id')->primary();

            // Brand-owned — ADR-0010.
            $table->foreignUlid('brand_id')->constrained()->cascadeOnDelete();

            // Event is a different module — plain ULID, no FK (ADR-0014).
            $table->ulid('event_id');

            $table->string('name');
            $table->string('area')->nullable();

            // D-STF-01: sign-up is self-service, but a team may opt into
            // approval — then a claim lands as `offered` and a lead confirms.
            $table->boolean('requires_approval')->default(false);

            $table->timestamps();

            $table->unique(['brand_id', 'event_id', 'name']);
        });

        Schema::create('shifts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('brand_id')->constrained()->cascadeOnDelete();

            // Same module — real FK allowed (ADR-0014).
            $table->foreignUlid('team_id')->constrained()->cascadeOnDelete();

            $table->ulid('event_id');

            $table->string('title');

            // Half-open `[starts_at, ends_at)`, the same convention ADR-0020
            // sets for bookings — but deliberately *not* an exclusion
            // constraint: ADR-0020 is explicit that "Agenda and Staffing do
            // not book". Two shifts may overlap; a person working both is a
            // rostering judgement, not a database error.
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');

            $table->timestamp('published_at')->nullable();

            $table->timestamps();

            $table->index(['brand_id', 'event_id', 'starts_at']);
        });

        Schema::create('shift_roles', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('brand_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('shift_id')->constrained()->cascadeOnDelete();

            $table->string('name');

            // The admission role a crew badge gets when someone accepts this
            // (D-STF-01). Decided here because Staffing knows what a person is
            // rostered as; Admission only knows what to print.
            $table->string('admission_role')->default('crew');

            $table->unsignedSmallInteger('required_headcount')->default(1);

            $table->timestamps();

            $table->unique(['shift_id', 'name']);
        });

        Schema::create('assignments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('brand_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('shift_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('shift_role_id')->constrained()->cascadeOnDelete();

            // Identity is a different module — plain ULID, no FK (ADR-0014).
            $table->ulid('user_id');

            $table->string('state', 16)->default('accepted');

            $table->timestamp('offered_at')->nullable();
            $table->timestamp('responded_at')->nullable();

            $table->timestamps();

            $table->index(['brand_id', 'user_id']);
            $table->index(['shift_role_id', 'state']);
        });

        // One live assignment per person per shift role. Without it, a double
        // click signs someone up twice and the coverage count silently lies
        // about how many people are actually coming.
        DB::statement(
            'CREATE UNIQUE INDEX assignments_one_live_per_person
             ON assignments (shift_role_id, user_id)
             WHERE state IN (\'offered\', \'accepted\', \'completed\')'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('assignments');
        Schema::dropIfExists('shift_roles');
        Schema::dropIfExists('shifts');
        Schema::dropIfExists('teams');
    }
};
