<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messaging_brand_settings', function (Blueprint $table): void {
            // ULID primary key — ADR-0014.
            $table->ulid('id')->primary();

            // Cross-module reference to Platform's `brands` table: a plain
            // ulid column with no FK constraint (ADR-0014) — Platform is a
            // different module, and a constraint would make the module
            // boundary a schema-level coupling. One row per brand.
            $table->ulid('brand_id')->unique();

            $table->string('from_name');
            $table->string('from_address');
            $table->string('reply_to')->nullable();

            // Encrypted at rest (ADR-0028) via the model's `encrypted:array`
            // cast — `text`, not `json`: the stored value is ciphertext.
            $table->text('transport')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messaging_brand_settings');
    }
};
