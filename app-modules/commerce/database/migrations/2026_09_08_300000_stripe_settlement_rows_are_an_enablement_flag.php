<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ADR-0058 point 9: Stripe Connect is gone, so a Stripe
     * `settlement_accounts` row stops being an account of its own and
     * becomes the flag that says "this brand sells through this instance's
     * Stripe account".
     *
     * - `statement_descriptor_suffix` is new and optional: what the payer
     *   reads on their card statement, so it names the brand rather than
     *   the operator. Stripe caps the whole descriptor at 22 characters,
     *   account prefix included, and truncates past it — the column is 22
     *   because nothing longer can ever reach Stripe intact, and the
     *   console validates it at save time.
     * - `provider_account_id` and `capabilities` are nulled for Stripe
     *   rows: nothing reads them any more, and a stale connected-account id
     *   left lying around is exactly what an old code path would pick up.
     *   The columns stay — Mollie and Unzer use `provider_account_id`, and
     *   history keeps the rest.
     * - A Stripe row whose onboarding never completed (`connected_at IS
     *   NULL`) is stamped connected: with no onboarding, "connected" is
     *   what every Stripe row now is.
     *
     * Production has no connected account and dev only sandbox ones, which
     * is why this is a deletion rather than a migration with money in it.
     */
    public function up(): void
    {
        Schema::table('settlement_accounts', function (Blueprint $table): void {
            $table->string('statement_descriptor_suffix', 22)->nullable()->after('checkout_label');
        });

        DB::table('settlement_accounts')
            ->where('provider', 'stripe')
            ->update([
                'provider_account_id' => null,
                'capabilities' => null,
                // Meaningless without capabilities: nothing sweeps them now.
                'capabilities_checked_at' => null,
                'connected_at' => DB::raw('COALESCE(connected_at, NOW())'),
            ]);
    }

    public function down(): void
    {
        Schema::table('settlement_accounts', function (Blueprint $table): void {
            $table->dropColumn('statement_descriptor_suffix');
        });
    }
};
