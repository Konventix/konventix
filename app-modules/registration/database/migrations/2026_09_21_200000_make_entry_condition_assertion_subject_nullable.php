<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Task 31 (spec §11): an assertion is anonymized with the registration
     * it belongs to, and the tombstone survives — `subject_user_id` and
     * `decided_by_user_id` are nulled, the row is kept so the registration's
     * own history stays coherent (Round 6 D-BATCH).
     *
     * `decided_by_user_id` was nullable from the start (an undecided row
     * names nobody); `subject_user_id` was not, because every row is written
     * by somebody. Deletion is the one moment that has to forget who — so
     * the column loses its NOT NULL rather than the row losing its place.
     * Nothing reads it back as a guaranteed value: the GDPR export is keyed
     * by the subject, never by this column's presence.
     */
    public function up(): void
    {
        Schema::table('registration_entry_condition_assertions', function (Blueprint $table): void {
            $table->ulid('subject_user_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('registration_entry_condition_assertions', function (Blueprint $table): void {
            $table->ulid('subject_user_id')->nullable(false)->change();
        });
    }
};
