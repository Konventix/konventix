<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The Registration-owned waitlist (decision log Round 7, D-REG-12:
     * all-or-nothing across a type's quotas, whole submission waitlists,
     * FIFO promotion with time-limited offers) and invitation/link tokens
     * (Round 6, D-COM-02: Registration owns access tokens; Commerce owns
     * price vouchers).
     *
     * A waitlist entry snapshots the submission's billing party so a later
     * conversion recreates exactly what was asked for, even if the form
     * has changed meanwhile.
     */
    public function up(): void
    {
        Schema::create('waitlist_entries', function (Blueprint $table): void {
            // ULID primary key — ADR-0014.
            $table->ulid('id')->primary();

            // Brand-owned — ADR-0010.
            $table->foreignUlid('brand_id')->constrained()->cascadeOnDelete();

            // Same-module reference, real FK allowed (ADR-0014).
            $table->foreignUlid('registration_type_id')->constrained()->cascadeOnDelete();

            // Event and Identity are different modules — plain ULIDs, no FK.
            $table->ulid('event_id');
            $table->ulid('registrant_user_id');

            $table->string('state')->default('queued');

            $table->string('organization_name')->nullable();
            $table->string('billing_recipient_name')->nullable();
            $table->string('billing_email')->nullable();
            $table->string('billing_address_line')->nullable();
            $table->string('billing_postal_code')->nullable();
            $table->string('billing_city')->nullable();
            $table->string('billing_country_code', 2)->nullable();
            $table->string('billing_vat_id')->nullable();

            $table->timestamp('queued_at');
            $table->timestamp('offered_at')->nullable();
            $table->timestamp('offer_expires_at')->nullable();
            $table->ulid('converted_registration_id')->nullable();

            $table->timestamps();

            $table->index(['registration_type_id', 'state', 'queued_at']);
        });

        Schema::create('invitation_tokens', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('brand_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('registration_type_id')->constrained()->cascadeOnDelete();

            $table->string('token', 64)->unique();
            $table->string('label')->nullable();
            $table->unsignedInteger('max_uses')->nullable();
            $table->unsignedInteger('uses')->default(0);
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invitation_tokens');
        Schema::dropIfExists('waitlist_entries');
    }
};
