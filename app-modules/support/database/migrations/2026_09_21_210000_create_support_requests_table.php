<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The report itself (design §4.1, D-SUP-03): what somebody typed into
     * the launcher, who they are as far as we know, and the technical
     * context they cannot be expected to quote themselves.
     *
     * `filed_on_brand_id` is **provenance, never a scope** (ADR-0050 §1).
     * The model deliberately does not use `BelongsToBrand`: the console
     * inbox lists across every brand and filters on this column, and a
     * report filed on the operator host has no brand at all, which a global
     * BrandScope would render invisible rather than console-filed. Nullable
     * for exactly that case (ADR-0063).
     *
     * The human reference is the last eight characters of the ULID,
     * upper-cased — the convention Commerce already uses for orders — so no
     * second identifier column exists to drift from the key.
     *
     * `external_reference`, `subject_module` and `subject_id` are reserved
     * now and filled later (design §16): the GitLab link is free text by the
     * SPEC's scope ceiling, and the typed reference is ADR-0022's "ask about
     * this" slot. Reserving them here is what keeps #88 from owing a second
     * migration.
     */
    public function up(): void
    {
        Schema::create('support_requests', function (Blueprint $table): void {
            // ULID primary key — ADR-0014. The last eight characters,
            // upper-cased, are the reference a person quotes.
            $table->ulid('id')->primary();

            // Provenance, not a scope: which brand host the report came in
            // on, null from the operator console (ADR-0050 §1, ADR-0063).
            $table->foreignUlid('filed_on_brand_id')->nullable()->constrained('brands')->nullOnDelete();

            // Identity is a different module — plain ULID, no FK (ADR-0014).
            // Nulled by the GDPR handler; the row itself survives.
            $table->ulid('user_id')->nullable();
            $table->string('email')->nullable();
            $table->boolean('contact_consent')->default(false);

            // At least one, from SupportCategory. A list, because a broken
            // purchase page is often a display problem too.
            $table->jsonb('categories');

            $table->text('message');

            $table->string('state', 32)->default('received');

            $table->boolean('is_known_issue')->default(false);
            $table->string('known_issue_notice', 500)->nullable();

            // Reserved (design §16): free text for a GitLab reference, and
            // the typed subject reference ADR-0022 asks for.
            $table->string('external_reference')->nullable();
            $table->string('subject_module', 40)->nullable();
            $table->ulid('subject_id')->nullable();

            // The SupportContextData snapshot of design §5.
            $table->jsonb('context');

            $table->ulid('assigned_to_user_id')->nullable();

            $table->timestamp('seen_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            // Set on resolved and wont_fix; `support:purge-requests` reads
            // this and nothing else as the retention clock (D-SUP-11).
            $table->timestamp('closed_at')->nullable();

            $table->timestamps();

            // The inbox's default order, the brand filter, and the two ways
            // the GDPR handler finds a subject's reports.
            $table->index(['state', 'created_at']);
            $table->index(['filed_on_brand_id', 'created_at']);
            $table->index('user_id');
            $table->index('email');

        });

        // Partial: the dialog's banner asks for the handful of rows that are
        // marked, never for the many that are not. Raw SQL because the
        // schema builder has no partial-index syntax (the same reason
        // `orders_pending_registration_sweep_index` is written this way).
        DB::statement(<<<'SQL'
            CREATE INDEX IF NOT EXISTS support_requests_known_issue_index
            ON support_requests (created_at)
            WHERE is_known_issue
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS support_requests_known_issue_index');
        Schema::dropIfExists('support_requests');
    }
};
