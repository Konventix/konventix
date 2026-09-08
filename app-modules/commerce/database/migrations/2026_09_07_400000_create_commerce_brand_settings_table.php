<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-brand Commerce settings (ADR-0057 point 4): today only the
     * repayment window — how many days a payer has to put back money that
     * was reversed before `commerce:cancel-unpaid-reversals` cancels the
     * order. A table rather than a config key because the ADR makes the
     * window per brand, not per deployment, and named for the module rather
     * than for the one column so the next Commerce-wide brand setting has a
     * home instead of a second table.
     *
     * One row per brand — `unique('brand_id')` —
     * `CommerceBrandSettings::forBrand()` hands back an unsaved defaulted
     * instance when nothing was configured, the same idiom
     * `WaitingRoomSettings::forBrand()` uses.
     */
    public function up(): void
    {
        Schema::create('commerce_brand_settings', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('brand_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('reversal_repayment_days')->default(7);
            $table->timestamps();
            $table->unique('brand_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commerce_brand_settings');
    }
};
