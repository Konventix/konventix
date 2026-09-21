<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Task 30 / ADR-0066 (spec §5.1): what a policy governs.
     *
     * A policy with **no** row here behaves exactly as it does today — brand-
     * wide, applicable everywhere that brand sells (D-CPL-04). That is what
     * makes this migration behaviour-neutral: it adds a table and changes the
     * meaning of no existing row.
     *
     * `brand_id` is nullable and mirrors `policies.brand_id` through
     * `MayBeOperatorWide` (D-CPL-05, #76): a brand binding the operator's
     * text to its own event carries its brand, the operator binding globally
     * carries null.
     *
     * One `kind` plus one `target_key` string rather than four typed columns
     * (D-CPL-02): three of the kinds are ULIDs of entities, the fourth is a
     * settlement provider's own key (`stripe`, `mollie`, `unzer`, `manual`),
     * which is not an entity at all — so a typed scheme would already be
     * inconsistent, and a fifth kind must not cost a migration. The console
     * does the type-checking the database is not doing, and a binding whose
     * target no longer exists simply never matches.
     *
     * The binding hangs on the **policy**, never on a version (T-4):
     * rebinding a text is not a new version of it, and the version is what a
     * person consented to.
     *
     * The index on `(kind, target_key)` is the resolver's query.
     */
    public function up(): void
    {
        Schema::create('policy_bindings', function (Blueprint $table): void {
            // ULID primary key — ADR-0014.
            $table->ulid('id')->primary();

            // Nullable: an operator-wide binding carries no brand (#76,
            // D-CPL-05). `MayBeOperatorWide` is what keeps `BelongsToBrand`
            // from stamping one onto it.
            $table->foreignUlid('brand_id')->nullable()->constrained()->cascadeOnDelete();

            // Within-module reference, so a real FK is fine (ADR-0014).
            $table->foreignUlid('policy_id')->constrained()->cascadeOnDelete();

            $table->string('kind', 32);
            $table->string('target_key', 64);

            // Identity is a different module — plain ULID, no FK (ADR-0014).
            $table->ulid('created_by_user_id')->nullable();

            $table->timestamps();

            // One binding per target per policy. A plain unique index is
            // right here — unlike `policies.(brand_id, slug)`, none of the
            // three columns is ever null.
            $table->unique(['policy_id', 'kind', 'target_key']);

            $table->index(['kind', 'target_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_bindings');
    }
};
