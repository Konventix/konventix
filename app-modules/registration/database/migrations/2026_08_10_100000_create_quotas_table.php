<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotas', function (Blueprint $table): void {
            // ULID primary key — ADR-0014.
            $table->ulid('id')->primary();

            // Brand-owned — ADR-0010. `BelongsToBrand` stamps this, never a
            // manual where('brand_id', ...) clause.
            $table->foreignUlid('brand_id')->constrained()->cascadeOnDelete();

            // A standalone entity, not a column on a type (SPEC.md "Owns" —
            // Quota). May be referenced by more than one RegistrationType
            // through the registration_type_quota pivot.
            $table->string('name');
            $table->unsignedInteger('capacity');
            $table->unsignedInteger('consumed')->default(0);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quotas');
    }
};
