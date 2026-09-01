<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brand_memberships', function (Blueprint $table): void {
            // Brand-owned media referenced across the module boundary — plain
            // ULIDs, no constraint (ADR-0014; ADR-0051 §1).
            $table->ulid('avatar_media_id')->nullable()->after('preferences');
            $table->ulid('banner_media_id')->nullable()->after('avatar_media_id');
        });
    }

    public function down(): void
    {
        Schema::table('brand_memberships', function (Blueprint $table): void {
            $table->dropColumn(['avatar_media_id', 'banner_media_id']);
        });
    }
};
