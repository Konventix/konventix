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
     *   account prefix included — the column is 22 because nothing longer
     *   can ever reach Stripe intact. The console's own rule is stricter
     *   (ten characters, what survives the longest legal prefix), because
     *   an overflow is refused rather than reliably truncated and the
     *   prefix cannot be read back since Connect went.
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
                // Deliberately not scoped to live rows: a Stripe row that was
                // abandoned mid-onboarding and then disconnected gets a
                // `connected_at` it never earned, which is harmless — every
                // reader checks `isLive()` first — and keeps the column's
                // new meaning ("every Stripe row is connected") true for the
                // whole table rather than for the live half of it.
                'connected_at' => DB::raw('COALESCE(connected_at, NOW())'),
            ]);
    }

    /**
     * Deliberately partial: this drops the column `up()` added, and does not
     * try to restore the `provider_account_id`, `capabilities` and
     * `capabilities_checked_at` values it nulled — they are gone, and a
     * rollback cannot invent connected accounts that Stripe no longer knows
     * about either. ADR-0058 point 9 accepts that because production holds no
     * connected account and dev only abandoned sandbox ones. The stamped
     * `connected_at` stays for the same reason.
     */
    public function down(): void
    {
        Schema::table('settlement_accounts', function (Blueprint $table): void {
            $table->dropColumn('statement_descriptor_suffix');
        });
    }
};
