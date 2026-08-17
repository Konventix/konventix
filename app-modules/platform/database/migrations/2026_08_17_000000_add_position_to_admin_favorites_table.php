<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Favorites carry a user-chosen order (ADR-0042): the sidebar list is
     * drag-reorderable, so the order is data rather than an `orderBy('label')`
     * in the shell. No unique(user_id, position) — a reorder rewrites
     * positions row by row and would trip the constraint mid-write; label is
     * the tie-breaker instead.
     */
    public function up(): void
    {
        Schema::table('admin_favorites', function (Blueprint $table): void {
            $table->unsignedSmallInteger('position')->default(0)->after('url');
        });

        // Existing rows keep the alphabetical order they were served in.
        $userIds = DB::table('admin_favorites')->distinct()->pluck('user_id');

        foreach ($userIds as $userId) {
            $ids = DB::table('admin_favorites')
                ->where('user_id', $userId)
                ->orderBy('label')
                ->pluck('id');

            foreach ($ids as $index => $id) {
                DB::table('admin_favorites')
                    ->where('id', $id)
                    ->update(['position' => $index + 1]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('admin_favorites', function (Blueprint $table): void {
            $table->dropColumn('position');
        });
    }
};
