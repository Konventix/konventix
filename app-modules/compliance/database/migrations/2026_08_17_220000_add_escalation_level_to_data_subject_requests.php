<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How far the deadline watch has already escalated a request (Round 6
 * D-BATCH: a Compliance scheduler watches the statutory deadline and
 * escalates through Messaging).
 *
 * A level rather than a timestamp, because the question the scheduler asks
 * daily is "has this one already been escalated *this far*" — the alternative
 * is either a mail every morning until someone acts, which trains operators
 * to ignore it, or a per-level timestamp column nobody reads.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('data_subject_requests', function (Blueprint $table): void {
            // 0 = untouched, 1 = deadline approaching, 2 = overdue.
            $table->unsignedTinyInteger('escalation_level')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('data_subject_requests', function (Blueprint $table): void {
            $table->dropColumn('escalation_level');
        });
    }
};
