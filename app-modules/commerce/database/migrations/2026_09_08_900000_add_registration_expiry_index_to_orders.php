<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The supporting index `commerce:expire-registration-orders` was shipped
     * without (#131, whole-branch review finding 4).
     *
     * The only index on `orders` is `(settles_type, settles_id)`, and
     * `settles_type = 'registration'` is a large fraction of the table — so
     * the sweep degenerated to a scan of every registration order ever
     * placed, ninety-six times a day, growing for as long as the instance
     * sells anything. Its two siblings both got one when they were written:
     * `carts` has `(status, expires_at)` for `commerce:expire-carts`,
     * `messaging_messages` has `(status, hold_until)` for
     * `messaging:flush-held`.
     *
     * Partial rather than composite: the sweep only ever asks about pending
     * registration orders, so the predicate belongs in the index and the
     * index stays a few rows wide however large `orders` grows. Written as
     * raw SQL because the schema builder has no partial-index syntax.
     *
     * Deliberately *not* `CONCURRENTLY`. A concurrent build cannot run
     * inside a transaction, which would make this the only migration in the
     * repository needing `$withinTransaction = false`, and it can leave an
     * invalid index behind on failure. A plain build takes a `SHARE` lock on
     * `orders` for the length of the build — reads are unaffected, writes
     * wait — and this runs before the 22.09 cutover, when the table is
     * small. Revisit if `orders` is ever large enough for that to matter.
     */
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE INDEX IF NOT EXISTS orders_pending_registration_sweep_index
            ON orders (created_at)
            WHERE status = 'pending' AND settles_type = 'registration'
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS orders_pending_registration_sweep_index');
    }
};
