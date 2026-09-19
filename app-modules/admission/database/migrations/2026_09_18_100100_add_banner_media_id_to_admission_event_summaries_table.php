<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The event's banner (#292), projected from `EventCreated` /
     * `EventDetailsUpdated` — a media-library ULID this module resolves
     * through Platform's `MediaLibrary`, never a URL (ADR-0035).
     */
    public function up(): void
    {
        Schema::table('admission_event_summaries', function (Blueprint $table): void {
            $table->ulid('banner_media_id')->nullable()->after('venue_id');
        });
    }

    public function down(): void
    {
        Schema::table('admission_event_summaries', function (Blueprint $table): void {
            $table->dropColumn('banner_media_id');
        });
    }
};
