<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Task 23 / D-REG-15: one partial cancellation of a confirmed
     * registration — the operator took `units` off its `quantity` and the
     * money for those units went back.
     *
     * The row's ULID is the idempotency key every module downstream keys
     * on, exactly as `registration_amendments.id` is for the additive
     * direction (ADR-0036): Admission stamps it on the admissions it
     * revoked, Resources records it on the claim it shrank, and Commerce
     * writes it on the credit note, so a replayed
     * `RegistrationQuantityReduced` can never revoke, release or refund a
     * second time.
     *
     * `reduced_by_user_id` is nullable for the same reason
     * `registrations.cancelled_by_user_id` is: the column must never claim
     * a person acted who did not. Today only the console writes here, so it
     * is always set — a future automated reduction would not be.
     */
    public function up(): void
    {
        Schema::create('registration_quantity_reductions', function (Blueprint $table): void {
            // ULID primary key — ADR-0014 — and the idempotency key itself.
            $table->ulid('id')->primary();

            // Brand-owned — ADR-0010. `BelongsToBrand` stamps this.
            $table->foreignUlid('brand_id')->constrained()->cascadeOnDelete();

            // Within-module reference, so a real FK is fine (ADR-0014).
            $table->foreignUlid('registration_id')->constrained()->cascadeOnDelete();

            $table->unsignedInteger('units');

            $table->string('reason_code');
            $table->text('reason_note')->nullable();

            // Identity is a different module — plain ULID, no FK (ADR-0014).
            $table->ulid('reduced_by_user_id')->nullable();

            $table->timestamp('reduced_at');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registration_quantity_reductions');
    }
};
