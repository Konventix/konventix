<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * `brands.operator_id` was nullable "until provisioning exists" (Platform
     * TODO.md). It exists: `CreateBrand` has always created a brand under an
     * operator, and slice 15b gave the operator's own identity a screen.
     *
     * The nullability was not a harmless default. A brand without an operator
     * has no legal seller, so `OperatorSettings::sellerForBrand()` throws at
     * the moment an invoice is issued — on a real order, mid-settlement, long
     * after the brand was created. A NOT NULL moves that failure to the
     * earliest possible point.
     *
     * Raw ALTER rather than `->change()`: Laravel's column change rebuilds the
     * column definition from the blueprint, and the foreign key and its
     * `restrictOnDelete` are not in that blueprint here — they would be
     * dropped silently. `SET NOT NULL` touches only the nullability.
     */
    public function up(): void
    {
        $this->backfillOrphanBrands();

        DB::statement('ALTER TABLE brands ALTER COLUMN operator_id SET NOT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE brands ALTER COLUMN operator_id DROP NOT NULL');
    }

    /**
     * Existing brands predating provisioning are adopted by the operator this
     * instance already runs as — there is exactly one (ADR-0001), so there is
     * no ambiguity about which.
     *
     * If there are orphans and no operator at all, the migration stops rather
     * than inventing a legal entity. A row that says who invoices are issued
     * by is not something a migration may make up.
     */
    private function backfillOrphanBrands(): void
    {
        $orphans = DB::table('brands')->whereNull('operator_id')->count();

        if ($orphans === 0) {
            return;
        }

        $operatorId = DB::table('operators')->orderBy('created_at')->value('id');

        if ($operatorId === null) {
            throw new RuntimeException(
                "Cannot require brands.operator_id: {$orphans} brand(s) have none and no operator exists to adopt them. "
                .'Seed an operator first (`php artisan db:seed`), then re-run this migration.'
            );
        }

        DB::table('brands')->whereNull('operator_id')->update(['operator_id' => $operatorId]);
    }
};
