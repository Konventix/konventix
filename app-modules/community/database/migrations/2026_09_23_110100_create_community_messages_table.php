<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One shoutbox line (design §4.2). `room_id` is a real FK — same module
     * (ADR-0014) — while `author_user_id` is a plain ULID, because accounts
     * are Identity's; display names come from `CommunityProfile`.
     *
     * `body` is `varchar(500)`, the 500-character cap of design §6, enforced
     * by the column type itself rather than validation alone.
     *
     * Deletion is a tombstone, not Eloquent's `SoftDeletes`: a removed line
     * keeps its row — and its place in `(room_id, id)` order — so a report
     * naming it still resolves and the desk can see what it acted on
     * (design §4.2). `deleted_at`/`deleted_by_user_id`/`deleted_reason` are
     * therefore plain nullable columns with no query scope hiding them; the
     * room's read path renders a tombstoned row as "Von der Orga entfernt"
     * and drops its body and author before it reaches other participants.
     *
     * `index(room_id, id)`: ULIDs are monotonic, so ordering by `id` is
     * chronological without trusting a clock, and this index makes the
     * "since this id" range scan the realtime client needs (and Task 5's
     * pagination) a primary-key range scan.
     */
    public function up(): void
    {
        Schema::create('community_messages', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('brand_id')->constrained()->cascadeOnDelete();

            $table->foreignUlid('room_id')->constrained('community_rooms')->cascadeOnDelete();
            // Plain ULID, no FK: accounts are Identity's (ADR-0014).
            $table->ulid('author_user_id');

            $table->string('body', 500);

            $table->timestampTz('deleted_at')->nullable();
            $table->ulid('deleted_by_user_id')->nullable();
            $table->string('deleted_reason')->nullable();

            $table->timestamps();

            $table->index(['room_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('community_messages');
    }
};
