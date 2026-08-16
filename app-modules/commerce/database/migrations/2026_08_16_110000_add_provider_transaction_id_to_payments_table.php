<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Providers separate the checkout identifier from the money identifier:
     * Stripe correlates webhooks by Checkout Session id but refunds against
     * the payment intent behind it. `external_id` stays the correlation key
     * (unique per provider); this column carries the id later money
     * operations act on, written by the success webhook.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->string('provider_transaction_id')->nullable()->after('external_id');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropColumn('provider_transaction_id');
        });
    }
};
