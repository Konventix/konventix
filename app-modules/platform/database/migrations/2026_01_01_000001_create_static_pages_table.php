<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('static_pages', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            // Brand-owned: this is the column shape every BelongsToBrand model
            // needs. Within Platform the constraint is fine; cross-module ULID
            // references carry no FK (ADR-0014).
            $table->foreignUlid('brand_id')->constrained()->cascadeOnDelete();

            $table->string('slug');
            $table->string('title');
            $table->json('blocks')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            // Slugs are unique per brand, not globally — two brands may both
            // have /imprint.
            $table->unique(['brand_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('static_pages');
    }
};
