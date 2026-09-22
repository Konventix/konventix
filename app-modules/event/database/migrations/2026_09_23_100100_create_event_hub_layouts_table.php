<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The operator's composition of the live hub for one event (design §6):
     * which cards and links show, in which order, and whether the brand's
     * home page becomes the hub for admitted members. One row per event —
     * the layout is replaced whole on every save, never patched. `cards` is
     * the ordered `list<{key, visible}>`; the keys name registry entries
     * Platform owns, so they are opaque strings here (ADR-0004).
     */
    public function up(): void
    {
        Schema::create('event_hub_layouts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('brand_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('event_id')->constrained('events')->cascadeOnDelete();
            $table->json('cards');
            $table->boolean('hub_replaces_home')->default(false);
            $table->timestamps();

            $table->unique('event_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_hub_layouts');
    }
};
