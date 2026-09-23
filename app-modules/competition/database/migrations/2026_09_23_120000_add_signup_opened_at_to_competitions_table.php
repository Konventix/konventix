<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When `SignupOpened` last went out for this competition (design §4; final
 * review, fix wave B, item 1).
 *
 * In `Published` signup is *derived* from the window, so nothing is written
 * when the window opens — and yet §4 says `SignupOpened` is emitted then, on
 * a scheduler tick. The tick needs to know whether it already said so, or it
 * would announce every open competition once a minute. `PublishCompetition`
 * and `ReopenSignup` stamp it too, since they emit the same event;
 * `UnpublishCompetition` clears it, so a competition published again is
 * announced again.
 *
 * Null on every existing row: the first tick after the deploy announces each
 * `Published` competition whose window is open, once. Nothing consumes
 * `SignupOpened` yet (GameOps 0.10 will), so that catch-up costs nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('competitions', function (Blueprint $table): void {
            $table->timestamp('signup_opened_at')->nullable()->after('signup_closes_at');
        });
    }

    public function down(): void
    {
        Schema::table('competitions', function (Blueprint $table): void {
            $table->dropColumn('signup_opened_at');
        });
    }
};
