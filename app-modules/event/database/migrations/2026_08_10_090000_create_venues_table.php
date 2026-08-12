<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('venues', function (Blueprint $table): void {
            // ULID primary key — ADR-0014.
            $table->ulid('id')->primary();

            // Brand-owned, reusable across that brand's events (SPEC.md "Owns").
            $table->foreignUlid('brand_id')->constrained()->cascadeOnDelete();

            $table->string('name');

            // Postal address.
            $table->string('address_line1');
            $table->string('address_line2')->nullable();
            $table->string('postal_code');
            $table->string('city');
            $table->string('country_code', 2);

            // Geo coordinates are optional — not every venue is pinned yet.
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();

            $table->text('access_notes')->nullable();

            // Typed structure (list of opening periods), decoded through
            // Konventix\Event\Models\Casts\OpeningTimesCast — a JSON column is
            // acceptable only behind a typed cast (ADR-0009).
            $table->json('opening_times')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('venues');
    }
};
