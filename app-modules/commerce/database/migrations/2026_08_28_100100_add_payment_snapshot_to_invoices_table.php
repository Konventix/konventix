<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ADR-0048: an invoice issued from `OrderPaid` states that it is
     * already paid — the PDF says so, the Factur-X XML carries the payment
     * means and the prepaid amount. Three columns, written once at issuance
     * alongside every other snapshot field and never touched again
     * (ADR-0031 immutability): `paid_at` the moment the money landed,
     * `paid_via` the human-readable settlement label (`ProviderLabels`),
     * `payment_reference` the succeeded payment's own external id, where the
     * adapter gave one. All three null on an open (invoice-settled,
     * not-yet-paid) invoice — `Invoice::isPaid()` reads `paid_at` alone.
     */
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->timestamp('paid_at')->nullable()->after('due_on');
            $table->string('paid_via', 64)->nullable()->after('paid_at');
            $table->string('payment_reference', 191)->nullable()->after('paid_via');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropColumn(['paid_at', 'paid_via', 'payment_reference']);
        });
    }
};
