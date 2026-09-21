<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which quantity reduction revoked this admission, if any (D-REG-15) —
     * the subtractive twin of `amendment_id`, and it exists for exactly the
     * reason that column does.
     *
     * Issuance asks "have I issued for *this amendment*?"; revocation now
     * asks "have I revoked for *this reduction*?". Without a key of its own
     * a replayed `RegistrationQuantityReduced` would find the next N free
     * admissions and take those away too, which is the failure mode a plain
     * "does this registration have revoked admissions?" guard cannot see.
     *
     * Registration owns the reduction row, so this is a plain ULID with no
     * FK constraint (ADR-0014).
     */
    public function up(): void
    {
        Schema::table('admissions', function (Blueprint $table): void {
            $table->ulid('reduction_id')->nullable()->after('amendment_id');

            $table->index('reduction_id');
        });
    }

    public function down(): void
    {
        Schema::table('admissions', function (Blueprint $table): void {
            $table->dropIndex(['reduction_id']);
            $table->dropColumn('reduction_id');
        });
    }
};
