<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The handle beside the display name (ADR-0065): a byline or a comment
     * author on the brand host renders `@username`, never the real name.
     * Nullable, because a row projected before this column existed carries
     * none until `identity:replay-profiles` re-emits the profile — and a
     * GDPR tombstone clears it again (ADR-0065 point 5).
     */
    public function up(): void
    {
        Schema::table('content_authors', function (Blueprint $table): void {
            $table->string('username')->nullable()->after('display_name');
        });
    }

    public function down(): void
    {
        Schema::table('content_authors', function (Blueprint $table): void {
            $table->dropColumn('username');
        });
    }
};
