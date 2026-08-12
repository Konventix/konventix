<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ADR-0017: the price declaration lives on `RegistrationType` as one
     * value object (JSON behind a typed Data cast, ADR-0009) — nullable,
     * because a `settlement: free` type has no price at all, not a zero one.
     * `terms_days` is the payment term the settlement event declares
     * (SPEC.md payload `terms_days`); it is not part of the price value
     * object because it describes when money is due, not what is owed.
     *
     * The billing fields on `registrations` complete the billing party for
     * invoice-settled types: a legally valid German invoice needs the
     * recipient's postal address, and reverse charge (ADR-0030) needs the
     * billing party's VAT ID and country.
     */
    public function up(): void
    {
        Schema::table('registration_types', function (Blueprint $table): void {
            $table->json('price_declaration')->nullable();
            $table->unsignedSmallInteger('terms_days')->default(14);
        });

        Schema::table('registrations', function (Blueprint $table): void {
            $table->string('billing_address_line')->nullable();
            $table->string('billing_postal_code')->nullable();
            $table->string('billing_city')->nullable();
            $table->string('billing_country_code', 2)->nullable();
            $table->string('billing_vat_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('registrations', function (Blueprint $table): void {
            $table->dropColumn([
                'billing_address_line',
                'billing_postal_code',
                'billing_city',
                'billing_country_code',
                'billing_vat_id',
            ]);
        });

        Schema::table('registration_types', function (Blueprint $table): void {
            $table->dropColumn(['price_declaration', 'terms_days']);
        });
    }
};
