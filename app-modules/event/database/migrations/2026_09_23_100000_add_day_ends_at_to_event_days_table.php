<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When an event day ends for the people inside (D-HUB-01): a LAN day
     * that runs until 03:00 must keep the live hub open until 03:00, not
     * lose it at midnight. Stored as `HH:MM` text rather than a `time`
     * column so the value round-trips unchanged through the form, the
     * `EventDaysChanged` payload and Admission's projection. Null means
     * the default, 00:00 of the following day.
     */
    public function up(): void
    {
        Schema::table('event_days', function (Blueprint $table): void {
            $table->string('day_ends_at', 5)->nullable()->after('label');
        });
    }

    public function down(): void
    {
        Schema::table('event_days', function (Blueprint $table): void {
            $table->dropColumn('day_ends_at');
        });
    }
};
