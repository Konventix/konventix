<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A dedup ledger for `PlaceSupplyChanged` (design §12b, #282): the
        // event carries an aggregate place count rather than row ids, so
        // idempotency cannot be read off any existing row the way a booking
        // or payment's own state already gives it for free — this table
        // exists only to remember which `changeId` was already applied.
        // `id` IS the event's `changeId`, not a fresh ULID: the primary key
        // is the dedup key. No `BelongsToBrand` — never queried by brand,
        // only ever looked up by its own id.
        Schema::create('quota_supply_change_receipts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('quota_id')->constrained('quotas')->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quota_supply_change_receipts');
    }
};
