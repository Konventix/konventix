<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One receipt per (claim, reduction) — D-REG-15's idempotency key on
     * this side, the same shape `quota_supply_change_receipts` gives
     * Registration's supply adjustments.
     *
     * A claim's `quantity` is a running number, not a state a replay can
     * converge on: applying the same reduction twice would shrink the claim
     * twice and release seats the buyer still owns. Admission can key on a
     * column of its own because it revokes rows; Resources decrements one,
     * so the fact that a reduction was applied has to be a row.
     *
     * Registration owns the reduction, so `reduction_id` is a plain ULID
     * with no FK constraint (ADR-0014).
     */
    public function up(): void
    {
        Schema::create('resource_claim_reduction_receipts', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            // Brand-owned — ADR-0010.
            $table->foreignUlid('brand_id')->constrained()->cascadeOnDelete();

            // Within-module reference, so a real FK is fine (ADR-0014).
            $table->foreignUlid('claim_id')->constrained('resource_claims')->cascadeOnDelete();

            $table->ulid('reduction_id');

            $table->unsignedInteger('units');

            $table->timestamps();

            $table->unique(['claim_id', 'reduction_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resource_claim_reduction_receipts');
    }
};
