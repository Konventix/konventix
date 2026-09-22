<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Projection of Event's `EventHubLayoutChanged` (design §6); an
     * accelerator, rebuildable by replay. The operator's card order and
     * visibility per event plus the home-page switch, so the hub page and
     * the home controller read one row here and never Event's table
     * (ADR-0004). `event_id` is Event's ULID — no FK constraint (ADR-0014).
     */
    public function up(): void
    {
        Schema::create('platform_hub_layouts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('brand_id')->constrained()->cascadeOnDelete();
            $table->ulid('event_id')->unique();
            $table->json('cards');
            $table->boolean('hub_replaces_home')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_hub_layouts');
    }
};
