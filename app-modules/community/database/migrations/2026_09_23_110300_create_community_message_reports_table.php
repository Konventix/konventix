<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The desk's queue (design §4.4). Any participant may report a line
     * once — `unique(message_id, reporter_user_id)` — and this table is
     * also the seam a later auto-moderation API feeds into (design §9,
     * D-COM-02): a classifier writes reports like any other reporter, and
     * the desk's workflow does not change.
     *
     * `message_id` is a real FK — same module (ADR-0014); a message purged
     * by the retention sweep takes its reports with it. `reporter_user_id`
     * and `resolved_by_user_id` are plain ULIDs, because accounts are
     * Identity's.
     */
    public function up(): void
    {
        Schema::create('community_message_reports', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('brand_id')->constrained()->cascadeOnDelete();

            $table->foreignUlid('message_id')->constrained('community_messages')->cascadeOnDelete();
            // Plain ULID, no FK: accounts are Identity's (ADR-0014).
            $table->ulid('reporter_user_id');

            $table->string('note')->nullable();

            // ReportState::Open->value.
            $table->string('state', 16)->default('open');
            $table->ulid('resolved_by_user_id')->nullable();
            $table->timestampTz('resolved_at')->nullable();

            $table->timestamps();

            $table->unique(['message_id', 'reporter_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('community_message_reports');
    }
};
