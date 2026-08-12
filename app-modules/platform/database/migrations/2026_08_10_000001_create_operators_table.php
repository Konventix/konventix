<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Operator data as rows, not config (ADR-0001). `brands.operator_id` is
     * nullable until operator provisioning exists — the single-operator
     * instance backfills through the seeder, and the column tightens to
     * non-null when brand creation goes through an Operator flow.
     */
    public function up(): void
    {
        Schema::create('operators', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('name');
            $table->string('legal_name');
            $table->string('contact_email')->nullable();
            $table->timestamps();
        });

        Schema::table('brands', function (Blueprint $table): void {
            // Same-module reference, so a real FK is allowed (ADR-0014 only
            // forbids them across module boundaries).
            $table->foreignUlid('operator_id')->nullable()->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('brands', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('operator_id');
        });

        Schema::dropIfExists('operators');
    }
};
