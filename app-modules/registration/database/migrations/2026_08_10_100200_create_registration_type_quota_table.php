<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A plain many-to-many pivot: a quota "may be referenced by more
        // than one RegistrationType" (SPEC.md "Owns" — Quota). Both sides
        // are owned by this same module, so real FKs are fine (ADR-0014).
        // No separate ULID identity of its own — the composite key is the
        // pivot's whole reason to exist.
        Schema::create('registration_type_quota', function (Blueprint $table): void {
            $table->foreignUlid('registration_type_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('quota_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['registration_type_id', 'quota_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registration_type_quota');
    }
};
