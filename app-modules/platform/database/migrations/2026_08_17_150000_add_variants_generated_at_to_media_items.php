<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Distinguishes "variants not generated yet" from "this file has none"
     * (ADR-0043): on a remote disk, variant generation is a queued job, so an
     * empty `variants` map is ambiguous for as long as the job is pending —
     * and an SVG or PDF legitimately never gets one.
     *
     * Null means pending. Everything uploaded so far was generated inline, so
     * it is stamped with the row's own creation time rather than "now": that
     * is when those variants actually came into being.
     */
    public function up(): void
    {
        Schema::table('media_items', function (Blueprint $table): void {
            $table->timestamp('variants_generated_at')->nullable()->after('variants');
        });

        DB::table('media_items')->update(['variants_generated_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('media_items', function (Blueprint $table): void {
            $table->dropColumn('variants_generated_at');
        });
    }
};
