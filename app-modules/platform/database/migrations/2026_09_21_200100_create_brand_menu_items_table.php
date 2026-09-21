<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The entries a brand shows in its topbar after the module entries the
     * `NavigationRegistry` contributes. Brand-owned (`BelongsToBrand`), unlike
     * the operator-wide footer: a menu is a brand's own face, and one legal
     * entity answering for all its brands has nothing to say about it.
     *
     * One level of hierarchy: a `parent` row groups `page` and `url` rows
     * through `parent_id`; the action refuses anything deeper. A `page` row
     * points at one of the brand's own pages and inherits that page's
     * visibility; the same-module FK takes the entry with the page when the
     * page is deleted (ADR-0014).
     */
    public function up(): void
    {
        Schema::create('brand_menu_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('brand_id')->constrained()->cascadeOnDelete();
            // The self-reference is constrained below, in its own statement:
            // a fluent `->primary()` is compiled as an ALTER that runs after
            // the column's foreign key ALTER, and Postgres refuses a foreign
            // key onto a table with no unique constraint yet (42830).
            $table->ulid('parent_id')->nullable();

            // 'page', 'url' or 'parent' — see BrandMenuItemKind. Exactly one
            // of the two destination columns is set for the first two kinds;
            // a parent has neither.
            $table->string('kind');
            // Nullable: a page entry falls back to the page's own title.
            $table->string('label', 80)->nullable();
            $table->foreignUlid('static_page_id')->nullable()->constrained('static_pages')->cascadeOnDelete();
            $table->string('url', 2048)->nullable();
            $table->boolean('opens_in_new_tab')->default(false);

            // Per parent: 0..n among the top-level rows, 0..n among each
            // parent's children.
            $table->unsignedSmallInteger('position');

            $table->timestamps();

            $table->index(['brand_id', 'parent_id', 'position']);
        });

        Schema::table('brand_menu_items', function (Blueprint $table): void {
            $table->foreign('parent_id')->references('id')->on('brand_menu_items')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_menu_items');
    }
};
