<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Resource types, resources and bookings (ADR-0020, ADR-0034).
     *
     * **The no-overlap invariant is a database constraint, not a check in an
     * Action.** ADR-0020 settles this outright: every booking carries a
     * half-open interval `[starts_at, ends_at)` and overlap is prevented by a
     * PostgreSQL `EXCLUDE USING gist`. An application-level check loses to a
     * race it cannot see; a constraint cannot be raced.
     *
     * Two consequences ADR-0020 names and accepts, both now real:
     *
     * 1. **`btree_gist` is required** — the exclusion combines an equality
     *    test on `resource_id` with a range-overlap test on the interval, and
     *    plain GiST cannot do equality on a ULID column. This is the first
     *    `CREATE EXTENSION` in the repo. A managed production Postgres may not
     *    grant the app role permission to create extensions; if so it must be
     *    enabled once by an administrator before this migration runs.
     * 2. **Migrations are now PostgreSQL-specific** and the project is no
     *    longer portable to MySQL. The test suite already runs on PostgreSQL,
     *    so that half of the cost was paid before this slice.
     *
     * The constraint is deliberately partial: only `held` and `confirmed`
     * bookings occupy an interval, so releasing one frees the slot while
     * keeping the record of who held it.
     */
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');

        Schema::create('resource_types', function (Blueprint $table): void {
            // ULID primary key — ADR-0014.
            $table->ulid('id')->primary();

            // Brand-owned — ADR-0010.
            $table->foreignUlid('brand_id')->constrained()->cascadeOnDelete();

            // Event is a different module — plain ULID, no FK (ADR-0014).
            // Per event only, no venue-level catalogue at release (D-EVT-13).
            $table->ulid('event_id');

            $table->string('name');
            $table->string('slug');

            // ADR-0034: 'unit' (a row per bookable unit) or 'quantity'
            // (one row with a capacity that bookings draw down).
            $table->string('mode', 16)->default('unit');

            // Holds expire so an abandoned checkout does not park a seat
            // forever.
            $table->unsignedInteger('hold_minutes')->default(30);

            $table->timestamps();

            $table->unique(['brand_id', 'event_id', 'slug']);
        });

        Schema::create('resources', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('brand_id')->constrained()->cascadeOnDelete();

            // Same module — real FK allowed (ADR-0014).
            $table->foreignUlid('resource_type_id')->constrained()->cascadeOnDelete();

            // Event owns venues — plain ULID, no FK (ADR-0014).
            $table->ulid('venue_id')->nullable();

            $table->string('label');

            // Only meaningful in quantity mode; unit-mode resources are one
            // bookable thing each, which is what capacity 1 says.
            $table->unsignedInteger('capacity')->default(1);

            $table->boolean('is_published')->default(false);

            $table->timestamps();

            $table->index(['brand_id', 'resource_type_id']);
        });

        Schema::create('resource_bookings', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('brand_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('resource_id')->constrained()->cascadeOnDelete();

            // Whoever holds the booking, by ULID and a short domain string —
            // a registration, a staffer, later a shop order. Never a
            // polymorphic Eloquent relation (ADR-0006, ADR-0014).
            $table->string('holder_type', 32);
            $table->ulid('holder_id');

            // The claim this booking fulfils, when it came from a
            // registration's grants. Null for a directly-made booking.
            $table->ulid('registration_id')->nullable();

            $table->unsignedInteger('quantity')->default(1);

            // Whether this booking claims its resource exclusively for the
            // interval. Denormalized from the type's capacity mode at booking
            // time, because the exclusion constraint below cannot join to
            // `resource_types` to find out — a constraint sees only this row.
            //
            // Unit-mode bookings are exclusive and the constraint enforces
            // them. Quantity-mode bookings are not: several may share an
            // interval as long as their quantities fit inside capacity, which
            // is a *sum* no Postgres constraint can express (ADR-0034), so
            // they are guarded in the Action instead.
            $table->boolean('is_exclusive')->default(true);

            $table->string('state', 16)->default('held');

            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->timestamp('hold_expires_at')->nullable();

            $table->timestamps();

            $table->index(['brand_id', 'holder_type', 'holder_id']);
            $table->index(['resource_id', 'state']);
        });

        // ADR-0020's invariant, as a constraint rather than a hope.
        // `tsrange(starts_at, ends_at, '[)')` is the half-open interval: a
        // booking ending at 14:00 and one starting at 14:00 do not overlap,
        // which is the whole reason the ADR chose half-open over inclusive.
        //
        // `tsrange` and not `tstzrange`: the columns are `timestamp without
        // time zone` holding UTC, and converting those to timestamptz depends
        // on the session TimeZone, which makes the expression non-IMMUTABLE
        // and Postgres refuses to index it. `tsrange` over the columns as
        // stored is immutable and compares exactly the values we wrote.
        //
        // Unit mode relies on this alone. Quantity mode layers a summed check
        // on top inside the booking transaction (ADR-0034), because no
        // Postgres constraint can express "the sum of overlapping quantities
        // stays under capacity".
        DB::statement(<<<'SQL'
            ALTER TABLE resource_bookings
            ADD CONSTRAINT resource_bookings_no_overlap
            EXCLUDE USING gist (
                resource_id WITH =,
                tsrange(starts_at, ends_at, '[)') WITH &&
            )
            WHERE (state IN ('held', 'confirmed') AND is_exclusive)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('resource_bookings');
        Schema::dropIfExists('resources');
        Schema::dropIfExists('resource_types');

        // The extension is deliberately left in place: other tables may come
        // to depend on it, and dropping it on a rollback would break them.
    }
};
