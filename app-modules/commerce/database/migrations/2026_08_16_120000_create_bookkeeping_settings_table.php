<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-operator DATEV export settings (ADR-0040). Keyed by operator —
     * the legal entity the tax authority sees (D-COM-05) — as a plain ULID,
     * never a relation (ADR-0014): Commerce may not touch the Operator
     * model. Account defaults are the SKR03 Automatikkonten, applied in
     * code when no row exists, so the columns stay nullable-free and a row
     * only records what an operator actually changed.
     */
    public function up(): void
    {
        Schema::create('bookkeeping_settings', function (Blueprint $table): void {
            // ULID primary key — ADR-0014.
            $table->ulid('id')->primary();

            $table->ulid('operator_id')->unique();

            // DATEV header identity; null until the advisor supplies them.
            $table->unsignedInteger('consultant_number')->nullable();
            $table->unsignedInteger('client_number')->nullable();

            $table->unsignedTinyInteger('gl_account_length')->default(4);

            // The collective debtor every document posts against (ADR-0040).
            $table->string('debtor_account', 9)->default('10000');

            // SKR03 Automatikkonten — BU-Schlüssel stays empty on purpose.
            $table->string('revenue_standard', 9)->default('8400');
            $table->string('revenue_reduced', 9)->default('8300');
            $table->string('revenue_reverse_charge', 9)->default('8336');
            $table->string('revenue_small_business', 9)->default('8195');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bookkeeping_settings');
    }
};
