<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An event's optional legacy slug (#309): the slug an older system published
 * the event under, kept so `/events/<legacy>` answers 301 onto the canonical
 * `/events/<slug>` after the cutover. `slug` stays canonical. Unique per
 * brand; that it never equals any event's `slug` is a cross-column rule the
 * Actions enforce, because no index can.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            $table->string('legacy_slug')->nullable()->after('slug');
            $table->unique(['brand_id', 'legacy_slug']);
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            $table->dropUnique(['brand_id', 'legacy_slug']);
            $table->dropColumn('legacy_slug');
        });
    }
};
