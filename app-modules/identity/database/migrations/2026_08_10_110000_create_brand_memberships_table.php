<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ADR-0016: one `users` row per human across the operator; the per-brand
     * relationship (first seen, preferences) lives here. `brand_id` is a plain
     * ULID — cross-module reference, no FK constraint (ADR-0014).
     */
    public function up(): void
    {
        Schema::create('brand_memberships', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->ulid('brand_id')->index();
            $table->timestamp('first_seen_at');
            $table->json('preferences')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'brand_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_memberships');
    }
};
