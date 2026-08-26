<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The nullable brand column the table was designed for: a brand's own
     * social links beside the operator's imprint, which stays operator-wide
     * because one legal entity answers for all its brands (ADR-0001).
     * Same-module FK (ADR-0014); a deleted brand takes its rows with it.
     */
    public function up(): void
    {
        Schema::table('footer_links', function (Blueprint $table): void {
            $table->foreignUlid('brand_id')->nullable()->after('operator_id')->constrained()->cascadeOnDelete();
            $table->index(['operator_id', 'brand_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::table('footer_links', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('brand_id');
        });
    }
};
