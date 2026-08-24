<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A brand's visual identity beyond its palette (ADR-0008 covers the
     * tokens): the mark in the topbar and the icon in the browser tab.
     *
     * Assets are media-library references, never paths — the library is the
     * only sanctioned way to turn a ULID into something renderable
     * (ADR-0035). Same module, so a real foreign key is allowed (ADR-0014);
     * `nullOnDelete` because deleting the file must degrade the brand to the
     * bundled fallback, never delete the brand.
     *
     * Two logos rather than one: the light mark disappears against a dark
     * surface, and a brand that themes dark mode (ADR-0008's
     * `theme_tokens_dark`) needs the same freedom for its mark. A brand that
     * sets only the light one gets it in both themes.
     */
    public function up(): void
    {
        Schema::table('brands', function (Blueprint $table): void {
            $table->foreignUlid('logo_media_id')->nullable()->constrained('media_items')->nullOnDelete();
            $table->foreignUlid('logo_dark_media_id')->nullable()->constrained('media_items')->nullOnDelete();
            $table->foreignUlid('favicon_media_id')->nullable()->constrained('media_items')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('brands', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('logo_media_id');
            $table->dropConstrainedForeignId('logo_dark_media_id');
            $table->dropConstrainedForeignId('favicon_media_id');
        });
    }
};
