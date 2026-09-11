<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A seat claim: Resources' own snapshot of a seating entitlement
     * (ADR-0060 point 8, D-EVT-06). An unallocated claim needs its own row —
     * a fake booking would occupy nothing and lie to the exclusion
     * constraint. The eligible categories are copied from the grant
     * (D-EVT-07), so eligibility is decided from this row alone and
     * Registration never learns what a plan is.
     *
     * The unique index coalesces `amendment_id` to '' because Postgres treats
     * NULLs as distinct: a plain unique over the nullable column would let a
     * replayed `RegistrationConfirmed` create a second claim.
     */
    public function up(): void
    {
        Schema::create('resource_claims', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('brand_id')->constrained()->cascadeOnDelete();

            // Registration and Identity are other modules — plain ULIDs
            // (ADR-0014). `registrant_user_id` authorises the picker and is
            // nulled on erasure.
            $table->ulid('event_id');
            $table->ulid('registration_id');
            $table->ulid('registrant_user_id')->nullable();
            $table->ulid('amendment_id')->nullable();

            // list<string> of resource type ids — the categories this claim
            // may be placed in.
            $table->jsonb('resource_type_ids');

            // A `SeatingMode` value: pick or assign.
            $table->string('seating_mode', 8);

            // Maximum number of places.
            $table->unsignedInteger('quantity')->default(1);

            // A `ClaimState` value.
            $table->string('state', 16)->default('unplaced');

            $table->timestamps();

            $table->index(['brand_id', 'registration_id']);
            $table->index(['brand_id', 'event_id', 'state']);
        });

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX resource_claims_registration_amendment_unique
            ON resource_claims (registration_id, COALESCE(amendment_id::text, ''))
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS resource_claims_registration_amendment_unique');

        Schema::dropIfExists('resource_claims');
    }
};
