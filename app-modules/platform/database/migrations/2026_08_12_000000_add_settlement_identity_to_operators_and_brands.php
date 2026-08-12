<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Seller identity and tax election for invoicing. The operator is the
     * legal entity an invoice is issued by (ADR-0001), so its postal address
     * and tax identifiers live here; the §19 UStG small-business election is
     * operator-level by law — a brand cannot be exempt while a sibling brand
     * of the same legal person is not (ADR-0030). `brands.currency` is fixed
     * per brand (decision log Round 6, D-BATCH).
     */
    public function up(): void
    {
        Schema::table('operators', function (Blueprint $table): void {
            $table->string('address_line')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('city')->nullable();
            $table->string('country_code', 2)->default('DE');
            $table->string('vat_id')->nullable();
            $table->string('tax_number')->nullable();
            $table->boolean('small_business_scheme')->default(false);
        });

        Schema::table('brands', function (Blueprint $table): void {
            $table->string('currency', 3)->default('EUR');
        });
    }

    public function down(): void
    {
        Schema::table('brands', function (Blueprint $table): void {
            $table->dropColumn('currency');
        });

        Schema::table('operators', function (Blueprint $table): void {
            $table->dropColumn([
                'address_line',
                'postal_code',
                'city',
                'country_code',
                'vat_id',
                'tax_number',
                'small_business_scheme',
            ]);
        });
    }
};
