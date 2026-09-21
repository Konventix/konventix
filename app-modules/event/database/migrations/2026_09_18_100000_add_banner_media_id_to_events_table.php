<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The event's banner (#292): one picture from the brand's media library,
     * referenced by ULID with no foreign key (ADR-0035, ADR-0014) — the
     * library tracks the reference through `media_usages` instead, so the
     * picture cannot be deleted while an event wears it.
     */
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            $table->ulid('banner_media_id')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            $table->dropColumn('banner_media_id');
        });
    }
};
