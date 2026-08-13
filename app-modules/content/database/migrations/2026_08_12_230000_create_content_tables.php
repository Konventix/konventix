<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Content entries and their comments.
     *
     * Three decisions are visible in what is *absent* here:
     *
     * - **No revision table** (D-CNT-09): no revision history at release. The
     *   audit trail records who published what when, so an editor needs no
     *   diff or rollback.
     * - **No locale column** (D-CNT-06): a single locale per brand at
     *   release; a translation layer is a post-release platform decision, and
     *   a nullable locale column now would pre-empt it badly.
     * - **No participant gate** (D-CNT-07): `audience` is public or
     *   login-required and nothing more, because gating on registration state
     *   would couple Content to Registration.
     */
    public function up(): void
    {
        Schema::create('content_entries', function (Blueprint $table): void {
            // ULID primary key — ADR-0014.
            $table->ulid('id')->primary();

            // Brand-owned — ADR-0010.
            $table->foreignUlid('brand_id')->constrained()->cascadeOnDelete();

            // Event is a different module — plain ULID, no FK (ADR-0014).
            // Nullable: most content belongs to a brand, not an event.
            $table->ulid('event_id')->nullable();

            // Identity owns users — plain ULID, no FK (ADR-0014).
            $table->ulid('author_user_id')->nullable();

            $table->string('type', 16)->default('news');
            $table->string('slug');
            $table->string('title');
            $table->text('teaser')->nullable();
            $table->longText('body')->nullable();

            $table->string('state', 16)->default('draft');
            $table->string('audience', 16)->default('everyone');

            // Announcement-only, and null everywhere else: Content declares
            // intent, Messaging decides what it means (D-CNT-08).
            $table->string('urgency', 16)->nullable();

            // When it goes live. Separate from `state` so a scheduled entry
            // that is unpublished before its date does not silently appear.
            $table->timestamp('published_at')->nullable();

            $table->boolean('comments_enabled')->default(false);

            $table->timestamps();

            $table->unique(['brand_id', 'slug']);
            $table->index(['brand_id', 'type', 'published_at']);
        });

        Schema::create('content_comments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('brand_id')->constrained()->cascadeOnDelete();

            // Same module — real FK allowed (ADR-0014).
            $table->foreignUlid('content_entry_id')->constrained()->cascadeOnDelete();

            // Not nullable, and that is the decision: comments require an
            // account (D-BATCH-7). There is no pseudonymous posting, so there
            // is no anonymous author to represent.
            $table->ulid('author_user_id');

            $table->text('body');
            $table->string('state', 16)->default('pending');

            $table->ulid('moderated_by_user_id')->nullable();
            $table->timestamp('moderated_at')->nullable();

            // A count rather than a table of reports: what a moderator needs
            // is a queue ordered by attention, not a forensic record of who
            // clicked the flag.
            $table->unsignedInteger('report_count')->default(0);

            $table->timestamps();

            $table->index(['brand_id', 'content_entry_id', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_comments');
        Schema::dropIfExists('content_entries');
    }
};
