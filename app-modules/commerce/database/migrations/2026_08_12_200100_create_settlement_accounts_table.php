<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The brand's connected settlement account (ADR-0007).
     *
     * The platform never holds funds — money moves between the participant and
     * the organizer's own PSP account — which is why every `SettlementAdapter`
     * call already takes an `$accountId`. Until now nothing existed for that
     * id to point at, and `BookManualPayment` passed a `manual` pseudo-account.
     * This is the row it names.
     *
     * `provider_account_id` and `credentials` stay null for the `manual`
     * provider: booking a bank transfer by hand needs no PSP at all, and it
     * must keep working for brands that never connect one.
     */
    public function up(): void
    {
        Schema::create('settlement_accounts', function (Blueprint $table): void {
            // ULID primary key — ADR-0014.
            $table->ulid('id')->primary();

            // Brand-owned — ADR-0010.
            $table->foreignUlid('brand_id')->constrained()->cascadeOnDelete();

            // 'manual' | 'null' | later 'stripe' | 'mollie'. A string rather
            // than an enum column so adding a provider is a code change in one
            // place, not a migration.
            $table->string('provider', 32);

            $table->string('label')->nullable();

            // The PSP's own identifier for the connected account.
            $table->string('provider_account_id')->nullable();

            // Encrypted at rest, following ADR-0028's pattern for per-brand
            // secrets. Never logged, never exported.
            $table->text('credentials')->nullable();

            $table->timestamp('connected_at')->nullable();
            $table->timestamp('disconnected_at')->nullable();

            $table->timestamps();

            $table->index(['brand_id', 'provider']);
        });

        // One live account per brand. A brand settling through two providers
        // at once has no defined answer for which one an order goes to, so the
        // database refuses the state rather than leaving it to whichever row
        // a query happened to return first.
        DB::statement(
            'CREATE UNIQUE INDEX settlement_accounts_one_live_per_brand
             ON settlement_accounts (brand_id)
             WHERE disconnected_at IS NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('settlement_accounts');
    }
};
