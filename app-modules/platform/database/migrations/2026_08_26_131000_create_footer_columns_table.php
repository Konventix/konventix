<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A footer column: a heading and a small block composition, the same
     * editing model a static page uses (slice 21b). Operator-owned, like
     * `footer_links` — a nullable `brand_id` narrows the list to one brand's
     * own columns without making the table brand-scoped.
     */
    public function up(): void
    {
        Schema::create('footer_columns', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('operator_id')->constrained()->cascadeOnDelete();
            // Null = operator-wide, else the brand's own column (slice 21b).
            $table->foreignUlid('brand_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('heading', 60)->nullable();
            // A small block composition — same registry as a page, typed
            // through the BlockData collection cast, validated on write.
            $table->json('blocks');
            $table->unsignedSmallInteger('position');
            $table->timestamps();
            $table->index(['operator_id', 'brand_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('footer_columns');
    }
};
