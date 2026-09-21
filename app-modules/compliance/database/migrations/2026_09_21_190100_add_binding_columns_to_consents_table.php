<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Task 30 / D-CPL-09 (spec §5.5): why this person was asked, and for
     * which purchase.
     *
     * Both columns are nullable and **no row is backfilled**: every consent
     * recorded before this migration carries null, which reads as "an
     * unbound policy, asked brand-wide" — which is exactly what it was.
     *
     * `policy_binding_id` is the binding that made the text applicable, null
     * for an unbound policy. No FK on purpose: a binding may be removed
     * later, and the consent must keep saying which one it was rather than
     * being rewritten or refusing the delete.
     *
     * `binding_context` is the scalars the resolution ran on — event,
     * registration types, entry conditions, settlement provider, and the
     * registration or order it belonged to. **Evidence, not a read model**:
     * it is read back on a single consent row and never queried to find
     * registrations. That is why it is one JSON column and not five indexed
     * ones.
     *
     * `capture_context` keeps its meaning (`checkout`, `registration`,
     * `consent_form`, `login`) and stays the coarse label.
     */
    public function up(): void
    {
        Schema::table('consents', function (Blueprint $table): void {
            $table->ulid('policy_binding_id')->nullable();
            $table->json('binding_context')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('consents', function (Blueprint $table): void {
            $table->dropColumn(['policy_binding_id', 'binding_context']);
        });
    }
};
