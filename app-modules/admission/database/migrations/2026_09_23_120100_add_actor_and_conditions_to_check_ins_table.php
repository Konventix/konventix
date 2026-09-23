<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Two facts a check-in did not record before the console could write one
     * (attendee-list design §3.1).
     *
     * `actor_user_id` is who checked the holder in from the console, and null
     * for a door scan. A plain `ulid` with no foreign key: users belong to
     * Identity (ADR-0014). Indexed, because the audit question "what did this
     * admin check in" reads by it.
     *
     * `conditions_checked` is the `[{key, outcome}]` list the door or the
     * operator answered (ADR-0066 §6.4), until now carried only on
     * `AdmissionCheckedIn`. Stamped on accepted rows only, null when nothing
     * was checked, and null on every rejected row, as the event has it.
     *
     * Nullable, with no backfill: every earlier row was a door scan, and its
     * outcomes already reached Registration through the event.
     */
    public function up(): void
    {
        Schema::table('check_ins', function (Blueprint $table): void {
            $table->ulid('actor_user_id')->nullable()->after('device_id')->index();
            $table->jsonb('conditions_checked')->nullable()->after('reason_code');
        });
    }

    public function down(): void
    {
        Schema::table('check_ins', function (Blueprint $table): void {
            $table->dropIndex(['actor_user_id']);
            $table->dropColumn(['actor_user_id', 'conditions_checked']);
        });
    }
};
