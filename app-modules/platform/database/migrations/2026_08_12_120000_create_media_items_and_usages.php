<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The brand-scoped media library (ADR-0035): uploads, generated
     * variants, and usage references. Other modules store a media ULID,
     * never a path — `media_usages` tracks who references what, which is
     * the basis for future cleanup (the cleanup job itself is not built,
     * per the ADR).
     */
    public function up(): void
    {
        Schema::create('media_items', function (Blueprint $table): void {
            // ULID primary key — ADR-0014.
            $table->ulid('id')->primary();

            // Brand-owned — ADR-0010.
            $table->foreignUlid('brand_id')->constrained()->cascadeOnDelete();

            $table->string('disk');
            $table->string('path');
            $table->string('original_filename');
            $table->string('mime_type');
            $table->unsignedBigInteger('size_bytes');
            $table->string('alt_text')->nullable();

            // Variant name -> storage path ("thumb" => "..."), generated at
            // upload time. A plain map, not entity rows — variants share the
            // item's lifecycle and are never referenced individually.
            $table->json('variants');

            // Identity is a different module — plain ULID, no FK (ADR-0014).
            $table->ulid('uploaded_by_user_id')->nullable()->index();

            $table->timestamps();
        });

        Schema::create('media_usages', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('brand_id')->constrained()->cascadeOnDelete();

            // Same-module reference, real FK allowed (ADR-0014).
            $table->foreignUlid('media_item_id')->constrained()->cascadeOnDelete();

            // The referencing side, as scalars: module name plus a domain
            // entity label and ULID — never a PHP class-string, so a module
            // rename in code does not orphan usage rows.
            $table->string('module');
            $table->string('entity_type');
            $table->ulid('entity_id');

            $table->timestamps();

            $table->unique(['media_item_id', 'module', 'entity_type', 'entity_id'], 'media_usages_reference_unique');
            $table->index(['module', 'entity_type', 'entity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_usages');
        Schema::dropIfExists('media_items');
    }
};
