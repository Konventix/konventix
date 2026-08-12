<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messaging_template_overrides', function (Blueprint $table): void {
            // ULID primary key — ADR-0014.
            $table->ulid('id')->primary();

            // Cross-module reference to Platform's `brands` table: plain
            // ulid, no FK constraint (ADR-0014).
            $table->ulid('brand_id');

            $table->string('template_key');
            $table->string('locale');
            $table->text('subject')->nullable();
            $table->longText('body')->nullable();

            $table->timestamps();

            $table->unique(['brand_id', 'template_key', 'locale']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messaging_template_overrides');
    }
};
