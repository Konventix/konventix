<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('registration_types', function (Blueprint $table): void {
            // ULID primary key — ADR-0014.
            $table->ulid('id')->primary();

            // Brand-owned — ADR-0010. `BelongsToBrand` stamps this, never a
            // manual where('brand_id', ...) clause.
            $table->foreignUlid('brand_id')->constrained()->cascadeOnDelete();

            // Event is a different module — plain ULID, no FK (ADR-0014).
            $table->ulid('event_id');

            $table->string('name');

            // The five explicit axes from ADR-0005 (SPEC.md "Owns" —
            // RegistrationType). Enum-backed string columns, cast to their
            // PHP backed enums on the model.
            $table->string('visibility')->default('public');
            $table->string('approval')->default('none');
            $table->string('settlement')->default('free');
            $table->string('confirmation_gate')->default('on_submit');

            // How many admissions of which role, and which resource claims,
            // a confirmed registration of this type is entitled to
            // (SPEC.md "Grant definitions"). JSON column behind a typed
            // DataCollection cast (ADR-0009) — never a free-form array.
            $table->json('grants')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registration_types');
    }
};
