<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Never chose a timezone" becomes a state the column can hold (#243).
 *
 * `2026_08_11_090000` declared `timezone` as `NOT NULL DEFAULT 'UTC'` at a
 * time when nothing read it. Once `formatDateTime()` started to, that default
 * stopped being inert: every account that had never opened the settings page
 * counted as having chosen UTC, and the browser fallback — the whole point of
 * the fallback — could only ever fire for a guest. A signed-in operator in
 * Berlin read the console on their own clock before #243 and in UTC after it.
 *
 * So: nullable, and the rows still carrying the untouched default are set to
 * NULL, because nobody picked it. An account that genuinely wants UTC picks it
 * on the settings page and the value is then indistinguishable from any other
 * deliberate choice — which is correct: what is being restored here is the
 * difference between a default and a decision, not a preference for Berlin.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('timezone')->nullable()->default(null)->change();
        });

        // Every row still on the default the previous migration wrote. There
        // was no way to set this column to UTC deliberately before #243 — the
        // admin mask is the only writer that existed, and an operator who
        // opened it would have had to type it — so this cannot clear a real
        // choice made earlier.
        DB::table('users')->where('timezone', 'UTC')->update(['timezone' => null]);
    }

    public function down(): void
    {
        DB::table('users')->whereNull('timezone')->update(['timezone' => 'UTC']);

        Schema::table('users', function (Blueprint $table): void {
            $table->string('timezone')->default('UTC')->nullable(false)->change();
        });
    }
};
