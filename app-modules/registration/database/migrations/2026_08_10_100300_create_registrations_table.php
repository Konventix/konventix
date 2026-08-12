<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('registrations', function (Blueprint $table): void {
            // ULID primary key — ADR-0014.
            $table->ulid('id')->primary();

            // Brand-owned — ADR-0010. `BelongsToBrand` stamps this, never a
            // manual where('brand_id', ...) clause.
            $table->foreignUlid('brand_id')->constrained()->cascadeOnDelete();

            // Within-module FK — RegistrationType is owned by this module.
            $table->foreignUlid('registration_type_id')->constrained()->restrictOnDelete();

            // Event and Identity are different modules — plain ULIDs, no FK
            // (ADR-0014). `registrant_user_id` is never nullable: D-REG-08
            // (docs/inventory/decision-log.md Round 5) — registrants must
            // hold an account in Phase 1, guest registration is deferred.
            $table->ulid('event_id');
            $table->ulid('registrant_user_id');

            // Two independent status fields (ADR-0005, SPEC.md "Two
            // independent status fields") — neither implies the other.
            $table->string('status')->default('submitted');
            $table->string('payment_status')->default('none');

            // The invoice recipient may be an organization rather than the
            // submitting user (SPEC.md "Owns" — Registration); all nullable,
            // a plain visitor answers none of them.
            $table->string('organization_name')->nullable();
            $table->string('billing_recipient_name')->nullable();
            $table->string('billing_email')->nullable();

            $table->timestamp('submitted_at');

            $table->timestamp('approved_at')->nullable();
            $table->ulid('approved_by_user_id')->nullable();

            $table->timestamp('rejected_at')->nullable();
            $table->ulid('rejected_by_user_id')->nullable();

            // Shared by RejectRegistration and CancelRegistration — a
            // business reason code, not a free-text field.
            $table->string('reason_code')->nullable();

            $table->timestamp('confirmed_at')->nullable();

            $table->timestamp('cancelled_at')->nullable();
            $table->ulid('cancelled_by_user_id')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registrations');
    }
};
