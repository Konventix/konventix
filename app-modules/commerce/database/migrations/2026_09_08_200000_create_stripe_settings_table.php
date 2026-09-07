<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * This instance's own Stripe account (ADR-0058 point 2). Keyed by
     * operator — the legal entity that *is* the merchant of record here
     * (ADR-0001, D-COM-05) — as a plain ULID, never a relation (ADR-0014):
     * Commerce may not touch the Operator model. The same row shape
     * `bookkeeping_settings` uses, for the same reason: the operator, not
     * the brand, is the party Stripe knows.
     *
     * All three key columns are `text` and cast `encrypted` on the model —
     * APP_KEY-encrypted at rest, never logged, never handed to a page prop.
     * They stay nullable so a row can hold only what an operator has
     * actually entered; a row without `secret_key` resolves to the
     * environment exactly as no row at all does.
     */
    public function up(): void
    {
        Schema::create('stripe_settings', function (Blueprint $table): void {
            // ULID primary key — ADR-0014.
            $table->ulid('id')->primary();

            $table->ulid('operator_id')->unique();

            // Ciphertext, so `text` rather than a sized string: the
            // encrypted form of a Stripe key is several times its length.
            $table->text('secret_key')->nullable();
            $table->text('webhook_secret')->nullable();

            // Optional and unused by hosted Checkout (ADR-0058 point 2):
            // no card field ever renders on a Konventix page, so nothing
            // needs a publishable key. Stored for completeness only.
            $table->text('publishable_key')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stripe_settings');
    }
};
