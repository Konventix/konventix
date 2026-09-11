<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A seat booking knows which claim it fulfils (design §4.3), so a claim
     * can find its bookings without scanning by registration. `nullOnDelete`:
     * a claim row may go while its booking history stays.
     *
     * The **column** is S0's: migration
     * `2026_09_10_000100_extend_resource_event_windows_and_bookings.php`
     * added `resource_bookings.claim_id` as a plain nullable ULID, because
     * S0's `HoldResourceBooking` already stamps it. This migration only
     * attaches the constraint and the index, now that `resource_claims`
     * exists — `foreignUlid()` here would emit `add column` again and
     * Postgres would abort with 42701.
     */
    public function up(): void
    {
        Schema::table('resource_bookings', function (Blueprint $table): void {
            $table->foreign('claim_id')->references('id')->on('resource_claims')->nullOnDelete();

            $table->index(['claim_id', 'state']);
        });
    }

    public function down(): void
    {
        Schema::table('resource_bookings', function (Blueprint $table): void {
            $table->dropIndex(['claim_id', 'state']);
            $table->dropForeign(['claim_id']);
        });
    }
};
