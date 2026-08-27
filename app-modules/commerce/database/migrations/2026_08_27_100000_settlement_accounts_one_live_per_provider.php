<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ADR-0046 reverses slice 7's one-live-account-per-brand rule: a brand
     * keeps one live account per provider and the payer chooses. The label
     * is what the checkout button says; the capabilities are what the PSP
     * last told us about the account (Stripe's card_payments status).
     */
    public function up(): void
    {
        DB::statement('DROP INDEX IF EXISTS settlement_accounts_one_live_per_brand');
        DB::statement('CREATE UNIQUE INDEX settlement_accounts_one_live_per_brand_provider ON settlement_accounts (brand_id, provider) WHERE disconnected_at IS NULL');

        Schema::table('settlement_accounts', function (Blueprint $table): void {
            $table->string('checkout_label', 80)->nullable()->after('label');
            $table->json('capabilities')->nullable()->after('credentials');
            $table->timestamp('capabilities_checked_at')->nullable()->after('capabilities');
        });
    }

    public function down(): void
    {
        Schema::table('settlement_accounts', function (Blueprint $table): void {
            $table->dropColumn(['checkout_label', 'capabilities', 'capabilities_checked_at']);
        });
        DB::statement('DROP INDEX IF EXISTS settlement_accounts_one_live_per_brand_provider');
        DB::statement('CREATE UNIQUE INDEX settlement_accounts_one_live_per_brand ON settlement_accounts (brand_id) WHERE disconnected_at IS NULL');
    }
};
