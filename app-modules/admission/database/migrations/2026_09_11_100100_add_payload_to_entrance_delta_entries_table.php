<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A third delta kind needs a payload the first two never did:
     * 'revoked' and 'checked_in' (`EntranceDeltaEntry`) are both fully
     * described by the row's own `admission_id` and `kind`, but
     * 'token_superseded' (design doc §5, Task 6) also has to carry the new
     * `token_nonce_hash`, or a device has nothing to compare a scanned
     * token's nonce against (Task 8). Nullable JSON because the other two
     * kinds still carry none — this is additive, not a shape every row
     * must now satisfy.
     */
    public function up(): void
    {
        Schema::table('entrance_delta_entries', function (Blueprint $table): void {
            $table->json('payload')->nullable()->after('kind');
        });
    }

    public function down(): void
    {
        Schema::table('entrance_delta_entries', function (Blueprint $table): void {
            $table->dropColumn('payload');
        });
    }
};
