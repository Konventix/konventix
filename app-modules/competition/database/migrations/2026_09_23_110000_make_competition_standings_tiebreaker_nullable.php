<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A standing's `tiebreaker` may be empty (design §7.2; final review, part 1,
 * I6).
 *
 * A leaderboard carries the entrant's aggregate in it — a lap time, a point
 * total. An entrant with no verified entry has no aggregate, and the column,
 * not nullable since 10b, got `0.0` instead: the public board showed "0 s"
 * beside a last place, which on a `lower` board reads as the best time in the
 * field. The bracket formats always write a number, so for them nothing
 * changes.
 *
 * A new migration rather than an edit of the 10b one: that table shipped.
 * The existing leaderboard rows keep their zero until the stage is next
 * recomputed, which the next verified score does.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('competition_standings', function (Blueprint $table): void {
            $table->double('tiebreaker')->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        DB::table('competition_standings')->whereNull('tiebreaker')->update(['tiebreaker' => 0]);

        Schema::table('competition_standings', function (Blueprint $table): void {
            $table->double('tiebreaker')->nullable(false)->default(0)->change();
        });
    }
};
