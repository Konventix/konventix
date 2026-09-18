<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Admission's read model of which place each admission sits on (#292
     * §5): projected from Resources' `ResourceBookingConfirmed` /
     * `ResourceBookingReleased` once they name an admission, never read
     * from Resources' tables (ADR-0004). One row per admission — a place
     * per ticket is the rule Resources enforces with a partial unique
     * index on its side, so this side needs no more than the same key.
     * Carries no personal data.
     */
    public function up(): void
    {
        Schema::create('admission_seats', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('brand_id')->constrained()->cascadeOnDelete();
            $table->ulid('admission_id')->unique();
            $table->ulid('booking_id')->index();
            $table->ulid('resource_type_id')->nullable();
            $table->string('place_ref', 64)->nullable();
            $table->string('place_label', 255);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admission_seats');
    }
};
