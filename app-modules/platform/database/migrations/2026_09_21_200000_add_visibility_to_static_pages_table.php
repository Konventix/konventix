<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Who may read a brand page: everyone, signed-in members, or people
     * holding a confirmed registration for one of the brand's events (see
     * `PageVisibility`). A string rather than a boolean pair so a fourth
     * audience is a new case, not a new column. Existing pages stay public.
     */
    public function up(): void
    {
        Schema::table('static_pages', function (Blueprint $table): void {
            $table->string('visibility')->default('public')->after('published_at');
        });
    }

    public function down(): void
    {
        Schema::table('static_pages', function (Blueprint $table): void {
            $table->dropColumn('visibility');
        });
    }
};
