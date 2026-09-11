<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Who bought the registration this admission came from (design doc
     * §13, 2026-09-11 amendment "a registration-derived admission is
     * anonymous today"). `IssueAdmissionsForRegistration` hard-set
     * `holder_user_id` to null and dropped `registrantUserId` from both
     * `RegistrationConfirmed` and `RegistrationAmended`, so a purchased
     * admission named nobody at all until a holder claimed it later. The
     * buyer is a *different* fact from the holder — group and B2B bookings
     * buy admissions for other people — and the participant's own ticket
     * list (design §4.1) needs to tell "I bought this" apart from "I hold
     * this".
     *
     * Nullable because a crew admission (`assignment_id` set,
     * `registration_id` null — see `2026_08_12_220000`) has no purchase
     * behind it at all, and because rows written before this migration have
     * neither a stored value nor a way to derive one from their own data —
     * `registration:announce-ownership` (Task 16) backfills those from
     * Registration's side.
     *
     * Identity is a different module — plain ULID, no FK (ADR-0014).
     */
    public function up(): void
    {
        Schema::table('admissions', function (Blueprint $table): void {
            $table->ulid('buyer_user_id')->nullable()->after('holder_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('admissions', function (Blueprint $table): void {
            $table->dropColumn('buyer_user_id');
        });
    }
};
