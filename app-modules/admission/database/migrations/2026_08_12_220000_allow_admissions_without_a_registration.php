<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Not every admission comes from a registration.
     *
     * D-STF-01 settles that an accepted shift assignment auto-creates the
     * crew admission through an event contract — Staffing emits, Admission
     * listens. But a crew member is rostered, not registered: there is no
     * commercial act behind their badge, and `registration_id` was a required
     * column with no honest value to put in it.
     *
     * Making it nullable is the smaller change than inventing a synthetic
     * registration, which would have put a fake row in Registration's tables
     * to satisfy a column in Admission's — exactly the kind of coupling
     * ADR-0005 draws the line against.
     *
     * `assignment_id` is the crew counterpart of `amendment_id`: the key
     * issuance is idempotent on. Every admission now carries exactly one
     * origin — registration (+ optional amendment), or assignment — and
     * `IssueAdmissionsForRegistration`'s guard keys on whichever applies.
     */
    public function up(): void
    {
        Schema::table('admissions', function (Blueprint $table): void {
            $table->ulid('registration_id')->nullable()->change();

            // Staffing owns assignments — plain ULID, no FK (ADR-0014).
            $table->ulid('assignment_id')->nullable()->after('amendment_id');

            $table->index(['brand_id', 'assignment_id']);
        });
    }

    public function down(): void
    {
        Schema::table('admissions', function (Blueprint $table): void {
            $table->dropIndex(['brand_id', 'assignment_id']);
            $table->dropColumn('assignment_id');
            $table->ulid('registration_id')->nullable(false)->change();
        });
    }
};
