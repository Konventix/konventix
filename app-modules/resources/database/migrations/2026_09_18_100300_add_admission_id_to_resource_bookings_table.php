<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A booking may name the admission it seats (#292 §6.2): a place per
     * ticket rather than per registration. Plain ULID, no foreign key —
     * admissions are Admission's rows (ADR-0014) — and nullable, because
     * operator reservations and the registration-level pick path that
     * predates this stay unbound.
     *
     * "One place per admission" is a partial unique index, not an
     * application check, for the reason `resource_bookings_no_overlap`
     * decides the other race (ADR-0020, `.ai/rules/seating.md`): two picks
     * for the same ticket racing each other lose at the database, and the
     * Action translates the failure the way it already translates 23P01.
     * Only occupying rows count, so a released booking leaves the ticket
     * free to sit somewhere else.
     */
    public function up(): void
    {
        Schema::table('resource_bookings', function (Blueprint $table): void {
            $table->ulid('admission_id')->nullable()->after('amendment_id');
            $table->index(['brand_id', 'admission_id']);
        });

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX resource_bookings_one_place_per_admission
            ON resource_bookings (admission_id)
            WHERE admission_id IS NOT NULL AND state IN ('held', 'confirmed')
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS resource_bookings_one_place_per_admission');

        Schema::table('resource_bookings', function (Blueprint $table): void {
            $table->dropIndex(['brand_id', 'admission_id']);
            $table->dropColumn('admission_id');
        });
    }
};
