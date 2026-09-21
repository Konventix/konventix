<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Task 19 (bugfix round 2026-09-21): the operator's ceiling on how many
     * units one registration of this type may buy.
     *
     * Nullable, and **null means one** — a type opts into a larger quantity
     * rather than opting out of it. Distinguishing "never set" from "set to
     * 1" is not worth a column default here: the reader is
     * `RegistrationType::maxQuantityPerRegistration()`, and the registration
     * form only shows its number input when the ceiling exceeds one.
     *
     * Not one of the axes `GuardsTypeConfiguration` freezes (ADR-0060): the
     * ceiling applies to the next submission, and lowering it reinterprets
     * no registration that already exists — the quantity each of those bought
     * is on their own row.
     */
    public function up(): void
    {
        Schema::table('registration_types', function (Blueprint $table): void {
            $table->unsignedInteger('max_quantity_per_registration')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('registration_types', function (Blueprint $table): void {
            $table->dropColumn('max_quantity_per_registration');
        });
    }
};
