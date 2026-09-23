<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What kind of checkpoint a row is (attendee-list design §3.1, §6): a
     * `door` a device is enrolled at, or the event's one `console`
     * checkpoint, which the operator console writes its check-ins against and
     * which no device enrolment surface ever offers (#328, ADR-0033).
     *
     * Every existing row is a door, so the default is the backfill.
     *
     * One console checkpoint per event is a partial unique index, written raw
     * because the schema builder has no `WHERE` on an index. It is what
     * `CheckInFromConsole`'s create-or-first converges on when two operators
     * check in the first guests of an event at the same moment.
     */
    public function up(): void
    {
        Schema::table('checkpoints', function (Blueprint $table): void {
            $table->string('kind', 16)->default('door')->after('name');
        });

        DB::statement("CREATE UNIQUE INDEX checkpoints_one_console_per_event ON checkpoints (brand_id, event_id) WHERE kind = 'console'");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS checkpoints_one_console_per_event');

        Schema::table('checkpoints', function (Blueprint $table): void {
            $table->dropColumn('kind');
        });
    }
};
