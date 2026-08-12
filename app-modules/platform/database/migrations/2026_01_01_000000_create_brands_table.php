<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brands', function (Blueprint $table): void {
            // ULID primary key — ADR-0014. Copy this shape for every table.
            $table->ulid('id')->primary();
            $table->string('name');
            $table->string('slug')->unique();
            $table->json('theme_tokens')->nullable();
            $table->timestamps();
        });

        Schema::create('brand_domains', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('brand_id')->constrained()->cascadeOnDelete();
            // Host lookup runs on every request; it must be unique and indexed.
            $table->string('host')->unique();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_domains');
        Schema::dropIfExists('brands');
    }
};
