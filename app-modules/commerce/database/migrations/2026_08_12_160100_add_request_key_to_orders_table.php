<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Order idempotency moves from "one order per settled thing" to "one
     * order per settlement *request*" (ADR-0036). A registration now maps to
     * N orders over its lifetime — the initial settlement plus one per
     * priced amendment — so `settles_id` alone can no longer be the key.
     *
     * The key is the ULID of whatever requested settlement: the registration
     * itself for the initial request, the `RegistrationAmendment` for an
     * amendment. Existing rows therefore backfill to `settles_id`, which is
     * exactly the key their original request would have carried.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->ulid('request_key')->nullable()->after('settles_id');
        });

        DB::table('orders')->whereNull('request_key')->update([
            'request_key' => DB::raw('settles_id'),
        ]);

        Schema::table('orders', function (Blueprint $table): void {
            $table->ulid('request_key')->nullable(false)->change();

            $table->unique(['settles_type', 'settles_id', 'request_key']);

            // The old non-unique ['settles_type','settles_id'] index is now
            // redundant: it is a strict prefix of the unique one above, which
            // serves the same "orders of a registration" lookups. Keeping
            // both would only cost writes.
            $table->dropIndex(['settles_type', 'settles_id']);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->index(['settles_type', 'settles_id']);
            $table->dropUnique(['settles_type', 'settles_id', 'request_key']);
            $table->dropColumn('request_key');
        });
    }
};
