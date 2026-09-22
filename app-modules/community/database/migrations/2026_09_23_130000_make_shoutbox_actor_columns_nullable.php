<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * GDPR erasure anonymises rather than deletes where a row is a fact
     * about somebody else (design §7; `CommunityGdprHandler`): a report the
     * subject filed stays as a fact about the message it names, and a mute
     * an erased organizer placed stays as a fact about the muted person. So
     * the two actor columns that were `NOT NULL` become nullable, the shape
     * `resolved_by_user_id`, `deleted_by_user_id` and `locked_by_user_id`
     * already had.
     *
     * `unique(message_id, reporter_user_id)` is unaffected in the way that
     * matters: Postgres treats NULLs as distinct, so any number of
     * anonymised reports may sit on one message, while a living reporter
     * can still file only one.
     */
    public function up(): void
    {
        Schema::table('community_message_reports', function (Blueprint $table): void {
            $table->ulid('reporter_user_id')->nullable()->change();
        });

        Schema::table('community_room_mutes', function (Blueprint $table): void {
            $table->ulid('muted_by_user_id')->nullable()->change();
        });
    }

    /**
     * Not reversible once erasure has run: a NULL has nothing to be put
     * back to. Anonymised rows are removed before the constraint returns,
     * which is the only honest down for a column that stopped being able
     * to say who.
     */
    public function down(): void
    {
        DB::table('community_message_reports')->whereNull('reporter_user_id')->delete();
        DB::table('community_room_mutes')->whereNull('muted_by_user_id')->delete();

        Schema::table('community_message_reports', function (Blueprint $table): void {
            $table->ulid('reporter_user_id')->nullable(false)->change();
        });

        Schema::table('community_room_mutes', function (Blueprint $table): void {
            $table->ulid('muted_by_user_id')->nullable(false)->change();
        });
    }
};
