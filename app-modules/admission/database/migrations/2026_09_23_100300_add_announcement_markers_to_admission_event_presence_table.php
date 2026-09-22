<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What the rest of the platform has actually been *told* about a
     * presence row, as opposed to what the row says (D-HUB-01).
     *
     * `ProjectEventPresence` runs inside Admission's queued job and the
     * handlers behind `ParticipantAdmitted` / `ParticipantHubAccessEnded` /
     * `ParticipantPresenceChanged` are not queued, so they run inline: a
     * `Cache::forget` against a blipping Redis, a deadlock, anything, fails
     * the job *after* the row was saved. Deriving "did this transition?"
     * from the row alone then suppresses the event on the retry for good —
     * the participant is left without a hub pill, or keeps access after a
     * revocation, and nothing says so.
     *
     * These two columns are the acknowledgement: they move only once the
     * event has been dispatched, so a retry re-emits exactly once and a
     * success never emits twice. Existing rows are backfilled from the
     * state they carry — everything already in the table was announced
     * under the old code.
     */
    public function up(): void
    {
        Schema::table('admission_event_presence', function (Blueprint $table): void {
            $table->boolean('announced_hub_access')->nullable();
            $table->string('announced_direction', 3)->nullable();
        });

        DB::table('admission_event_presence')->update([
            'announced_hub_access' => DB::raw('hub_access'),
            'announced_direction' => DB::raw('last_direction'),
        ]);
    }

    public function down(): void
    {
        Schema::table('admission_event_presence', function (Blueprint $table): void {
            $table->dropColumn(['announced_hub_access', 'announced_direction']);
        });
    }
};
