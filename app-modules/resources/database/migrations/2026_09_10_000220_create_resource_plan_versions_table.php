<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per plan save (design §4.5), written inside the save
     * transaction and pruned to the last 50 per plan. No diff, no restore UI
     * in v1 — this is the audit trail and the "what did it look like before
     * the operator deleted row C" answer.
     *
     * No `updated_at`: a version is written once.
     */
    public function up(): void
    {
        Schema::create('resource_plan_versions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('brand_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('plan_id')->constrained('resource_plans')->cascadeOnDelete();

            $table->integer('version');
            $table->jsonb('document');

            // A `PlanVersionSource` value: editor, import, copy.
            $table->string('source', 16);

            // A user id (Identity), nulled on erasure — no FK (ADR-0014).
            $table->ulid('created_by')->nullable();

            $table->timestamp('created_at')->nullable();

            $table->unique(['plan_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resource_plan_versions');
    }
};
