<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-brand dark-mode overrides (ADR-0008 backlog). `theme_tokens` was one
     * map applied to `:root` whatever the colour scheme, so a brand could say
     * nothing about `.dark` at all — and a dark accent, set in the only map
     * there was, leaked into light mode.
     *
     * A second column rather than reshaping the first into `{light, dark}`.
     * The tidier shape would need a data migration over every brand's palette
     * and would change the meaning of a column three other places already read;
     * this way every existing row keeps meaning exactly what it meant, and
     * `theme_tokens` simply becomes explicitly "the light map".
     */
    public function up(): void
    {
        Schema::table('brands', function (Blueprint $table): void {
            $table->json('theme_tokens_dark')->nullable()->after('theme_tokens');
        });
    }

    public function down(): void
    {
        Schema::table('brands', function (Blueprint $table): void {
            $table->dropColumn('theme_tokens_dark');
        });
    }
};
