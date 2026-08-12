<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Amendments to confirmed registrations (ADR-0036): one row per delta.
     * The row's ULID is the settlement request key Commerce keys its order
     * idempotency on, and the amendment key Admission keys delta issuance
     * on — so this table is what makes "one registration, N orders" safe.
     */
    public function up(): void
    {
        Schema::create('registration_amendments', function (Blueprint $table): void {
            // ULID primary key — ADR-0014.
            $table->ulid('id')->primary();

            // Brand-owned — ADR-0010. `BelongsToBrand` stamps this, never a
            // manual where('brand_id', ...) clause.
            $table->foreignUlid('brand_id')->constrained()->cascadeOnDelete();

            // Same-module reference, so a real FK is allowed (ADR-0014).
            $table->foreignUlid('registration_id')->constrained()->cascadeOnDelete();

            // The added grants, same shape as RegistrationType::$grants —
            // a JSON column behind a typed DataCollection cast (ADR-0009).
            $table->json('grants');

            // Null for a free amendment: added crew badges come with the
            // exhibitor's package, so they add admissions without adding
            // money and never reach Commerce at all.
            $table->json('lines')->nullable();

            $table->string('note')->nullable();

            $table->timestamp('amended_at');

            $table->timestamps();

            $table->index(['registration_id', 'amended_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registration_amendments');
    }
};
